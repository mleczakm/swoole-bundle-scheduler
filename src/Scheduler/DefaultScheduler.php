<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Scheduler;

use Closure;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Throwable;

/**
 * Polls every registered {@see ScheduleProviderInterface}'s due messages and dispatches them
 * through the message bus, the same way {@see \Symfony\Component\Scheduler\Messenger\SchedulerTransport}
 * does when consumed by a `messenger:consume scheduler_*` worker - PreRunEvent/PostRunEvent/FailureEvent
 * included, so listeners written against Symfony's own scheduler events behave identically here.
 *
 * Unlike that transport, nothing here needs a dedicated blocking worker process: {@see run()} is
 * meant to be called repeatedly by something else driving the polling loop - see
 * {@see \SwooleBundle\Scheduler\Swoole\WithScheduler}, which drives it off Swoole's own event
 * loop instead of a separate OS process.
 */
final class DefaultScheduler implements Scheduler
{
    /**
     * @var list<MessageGenerator>
     */
    private array $generators = [];

    private int $index = 0;

    /**
     * A no-arg callable run once before each poll pass and once before every message is
     * dispatched. Intended for cheap, idempotent readiness checks - e.g. making sure a
     * pooled database connection is still alive before a stateful schedule's checkpoint is
     * read. Kept null by default.
     */
    private readonly ?Closure $beforeRun;

    /**
     * @param iterable<ScheduleProviderInterface> $scheduleProviders a tagged iterator of
     *        `scheduler.schedule_provider` services, or a plain array
     * @param (callable(): void)|null $beforeRun
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        iterable $scheduleProviders,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?callable $beforeRun = null,
    ) {
        $this->beforeRun = $beforeRun !== null ? $beforeRun(...) : null;

        foreach ($scheduleProviders as $scheduleProvider) {
            $this->addSchedule($scheduleProvider->getSchedule());
        }
    }

    public function addSchedule(Schedule $schedule): void
    {
        $this->addMessageGenerator(new MessageGenerator($schedule, 'schedule_' . $this->index, $this->clock));
        ++$this->index;
    }

    public function addMessageGenerator(MessageGenerator $generator): void
    {
        $this->generators[] = $generator;
    }

    public function run(): void
    {
        if ($this->beforeRun !== null) {
            ($this->beforeRun)();
        }

        foreach ($this->generators as $generator) {
            foreach ($generator->getMessages() as $context => $message) {
                if ($this->beforeRun !== null) {
                    ($this->beforeRun)();
                }

                if (! $this->dispatcher) {
                    $this->bus->dispatch($message);

                    continue;
                }

                $preRunEvent = new PreRunEvent($generator->getSchedule(), $context, $message);
                $this->dispatcher->dispatch($preRunEvent);

                if ($preRunEvent->shouldCancel()) {
                    continue;
                }

                try {
                    $this->bus->dispatch($message);

                    $this->dispatcher->dispatch(new PostRunEvent($generator->getSchedule(), $context, $message));
                } catch (Throwable $error) {
                    $failureEvent = new FailureEvent($generator->getSchedule(), $context, $message, $error);
                    $this->dispatcher->dispatch($failureEvent);

                    if (! $failureEvent->shouldIgnore()) {
                        throw $error;
                    }
                }
            }
        }
    }
}
