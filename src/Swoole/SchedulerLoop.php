<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Swoole;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Swoole\Coroutine;
use Swoole\Http\Server;
use Swoole\Timer;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerExitedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStoppedEvent;
use SwooleBundle\SwooleBundle\Server\Configurator\Configurator;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Post-fork, worker-zero scheduler. One-shot timers serialize passes.
 * A timeout reports a stall; it must never re-enter the shared MessageGenerators.
 * The configurator constructs shared heartbeat memory and initializes health before fork.
 */
final class SchedulerLoop implements Configurator, EventSubscriberInterface
{
    private const int RETRY_BASE_MS = 1_000;

    private bool $running = false;

    private ?int $timerId = null;

    private ?int $watchdogId = null;

    private int $consecutiveFailures = 0;

    private bool $inFlight = false;

    private readonly LoggerInterface $logger;

    /**
     * @param positive-int $intervalMs the longest the loop sleeps between passes
     * @param positive-int|null $watchdogTimeoutSeconds
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly CoWrapper $coWrapper,
        private readonly SchedulerHeartbeat $heartbeat,
        ?LoggerInterface $logger = null,
        private readonly ClockInterface $clock = new Clock(),
        private readonly int $intervalMs = 60_000,
        private readonly ?int $watchdogTimeoutSeconds = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::NAME => 'onWorkerStarted',
            // onWorkerExit fires repeatedly while a reloading worker waits for its event loop to
            // drain; a pending timer is exactly what would keep that loop alive until
            // max_wait_time. onWorkerStop covers a worker that exits without going through it.
            WorkerExitedEvent::NAME => 'stop',
            WorkerStoppedEvent::NAME => 'stop',
        ];
    }

    public function configure(Server $server): void
    {
        $this->heartbeat->expect($this->clock->now());
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        // Worker ids start with the event (HTTP) workers, so #0 is never a task worker.
        if ($event->getWorkerId() !== 0) {
            return;
        }

        $this->start();
    }

    /**
     * Arms the first pass, to run as soon as the event loop is free: it dispatches whatever
     * became due while the server was down and learns when to wake up next.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->consecutiveFailures = 0;
        $this->arm(1, expect: true);
    }

    public function stop(): void
    {
        $this->running = false;

        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }

        if ($this->watchdogId !== null) {
            Timer::clear($this->watchdogId);
            $this->watchdogId = null;
        }
    }

    public function tick(): void
    {
        $this->timerId = null;

        if (! $this->running) {
            return;
        }

        if ($this->inFlight) {
            return;
        }
        if (! $this->heartbeat->acquire()) {
            $this->arm(1000, expect: false);

            return;
        }
        $this->inFlight = true;
        $this->startWatchdog();
        $delayMs = $this->intervalMs;
        $completed = false;

        try {
            // Registers this coroutine to release/reset every pooled stateful service it touches
            // when it ends - the same mechanism request and message boundaries use. Without it
            // nothing pooled that Scheduler::run() touches (an entity manager, an event
            // dispatcher) would ever be reset between passes.
            $this->coWrapper->defer();

            $delayMs = $this->delayUntil($this->scheduler->run());

            $this->heartbeat->beat();
            $this->consecutiveFailures = 0;
            $completed = true;
        } catch (Throwable $e) {
            // Nothing above a timer callback catches an exception: left alone it is a fatal
            // error that takes the worker - and every request in flight in it - down.
            $this->logger->error('Scheduler tick failed', [
                'exception' => $e,
            ]);

            $delayMs = $this->retryDelay();
        } finally {
            $this->heartbeat->release();
            $this->inFlight = false;
            $this->clearWatchdog();
            $this->arm($delayMs, expect: $completed);
        }
    }

    /**
     * @param positive-int $delayMs
     * @param bool $expect whether to tell the heartbeat when this pass is expected. Only true
     *   once a pass has completed (or the loop has just started): a retry after a failed pass leaves the previous expectation in place, so a scheduler that keeps
     *   failing falls further and further behind in the health check instead of looking
     *   punctual because it keeps trying.
     */
    private function arm(int $delayMs, bool $expect): void
    {
        if (! $this->running) {
            return;
        }

        if ($expect) {
            $this->heartbeat->expect($this->clock->now()->modify(sprintf('+%d milliseconds', $delayMs)));
        }

        $this->timerId = Timer::after($delayMs, function (): void {
            // In a server worker Swoole already runs timer callbacks in a coroutine; make sure
            // of it rather than assume, since CoWrapper::defer() is fatal outside one.
            if (Coroutine::getCid() === -1) {
                Coroutine::create($this->tick(...));

                return;
            }

            $this->tick();
        });
    }

    /**
     * @return positive-int
     */
    private function delayUntil(?DateTimeImmutable $next): int
    {
        if ($next === null) {
            return $this->intervalMs;
        }

        $now = $this->clock->now();

        // Rounded up: waking a fraction of a millisecond early finds nothing due yet.
        $delayMs = ($next->getTimestamp() - $now->getTimestamp()) * 1000
            + (int) ceil(((int) $next->format('u') - (int) $now->format('u')) / 1000);

        return max(1, min($this->intervalMs, $delayMs));
    }

    /**
     * @return positive-int
     */
    private function retryDelay(): int
    {
        $delayMs = self::RETRY_BASE_MS * 2 ** min($this->consecutiveFailures, 16);
        ++$this->consecutiveFailures;

        return max(1, min($this->intervalMs, $delayMs));
    }

    private function startWatchdog(): void
    {
        if ($this->watchdogTimeoutSeconds === null) {
            return;
        }

        $this->watchdogId = Timer::after($this->watchdogTimeoutSeconds * 1000, function (): void {
            $this->watchdogId = null;
            $this->logger->error(
                'Scheduler tick exceeded its timeout; waiting for the active pass to finish',
                ['timeout_seconds' => $this->watchdogTimeoutSeconds],
            );
        });
    }

    private function clearWatchdog(): void
    {
        if ($this->watchdogId === null) {
            return;
        }

        Timer::clear($this->watchdogId);
        $this->watchdogId = null;
    }
}
