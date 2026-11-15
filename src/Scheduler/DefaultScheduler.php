<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Scheduler;

use Closure;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Throwable;

/**
 * Dispatches every registered {@see ScheduleProviderInterface}'s due messages through the
 * message bus, with the same PreRunEvent / PostRunEvent / FailureEvent Symfony's own
 * `messenger:consume scheduler_*` worker dispatches, so listeners written against Symfony's
 * scheduler events behave identically here.
 *
 * Two deliberate differences from that worker:
 *
 * - Messages are *dispatched*, not handled in place: they go through the bus's normal routing,
 *   so a message routed to an async transport (Swoole task workers, say) leaves this process
 *   immediately and only unrouted / sync messages run inside the caller's coroutine.
 * - {@see run()} is a single non-blocking pass that reports when it next has work, instead of a
 *   `sleep()` loop - {@see \SwooleBundle\Scheduler\Swoole\SchedulerLoop} uses that to wake up
 *   exactly when a message is due.
 */
final class DefaultScheduler implements Scheduler
{
    /**
     * Built on the first pass, not in the constructor: this service is instantiated before the
     * Swoole server forks, and building a schedule pulls in whatever the application's provider
     * depends on (cache pools, database connections) - none of which belongs in the master.
     *
     * @var list<MessageGenerator>|null
     */
    private ?array $generators = null;

    /**
     * A no-arg callable run before a pass that has due messages touches its checkpoint, and
     * again before every message is dispatched. Intended for cheap, idempotent readiness checks
     * - e.g. making sure a pooled database connection is still alive. Does not run on a pass
     * that has nothing due.
     */
    private readonly ?Closure $beforeRun;

    /**
     * Reads {@see MessageGenerator}'s private `$waitUntil`: the moment its next message is due
     * (null once the schedule has none left).
     *
     * The generator computes exactly this on every pass and keeps it to itself. The supported
     * way to observe it would be a decorating checkpoint - `release()` is handed the same value
     * - but although the generator's constructor accepts any CheckpointInterface, its private
     * `checkpoint(): Checkpoint` return type rejects everything except Symfony's own final class
     * (true for 7.4 through 8.2). Re-deriving it from the triggers would mean calling
     * application trigger code outside the generator, which is wrong for stateful and callback
     * triggers. So: one read of one private property, and a one-second polling fallback if it ever goes away.
     *
     * @var (Closure(MessageGenerator): mixed)|null
     */
    private readonly ?Closure $readWaitUntil;

    /**
     * @param iterable<array-key, ScheduleProviderInterface> $scheduleProviders keyed by schedule
     *        name - a `scheduler.schedule_provider` tagged iterator indexed by its `name`
     *        attribute. Integer keys (a plain list) fall back to `schedule_<position>`.
     * @param (callable(): void)|null $beforeRun
     */
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly iterable $scheduleProviders,
        private readonly ClockInterface $clock = new Clock(),
        private readonly ?EventDispatcherInterface $dispatcher = null,
        ?callable $beforeRun = null,
    ) {
        $this->beforeRun = $beforeRun !== null ? $beforeRun(...) : null;
        $this->readWaitUntil = property_exists(MessageGenerator::class, 'waitUntil')
            ? Closure::bind(
                static fn(MessageGenerator $generator): mixed => $generator->waitUntil,
                null,
                MessageGenerator::class,
            )
            : null;
    }

    public function run(): ?DateTimeImmutable
    {
        $next = null;

        foreach ($this->generators() as $generator) {
            if ($this->beforeRun !== null && $this->mayHaveDueMessages($generator)) {
                ($this->beforeRun)();
            }

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
                    $envelope = $this->bus->dispatch($message);

                    $this->dispatcher->dispatch(new PostRunEvent($generator->getSchedule(), $context, $message, $envelope->last(HandledStamp::class)?->getResult()));
                } catch (Throwable $error) {
                    $failureEvent = new FailureEvent($generator->getSchedule(), $context, $message, $error);
                    $this->dispatcher->dispatch($failureEvent);

                    if (! $failureEvent->shouldIgnore()) {
                        throw $error;
                    }
                }
            }

            $due = $this->nextRunAt($generator);

            if ($due !== null && ($next === null || $due < $next)) {
                $next = $due;
            }
        }

        return $next;
    }

    /**
     * Whether the generator's next getMessages() call will get as far as its checkpoint. False
     * only when it is known to return straight away - so when in doubt, true.
     */
    private function mayHaveDueMessages(MessageGenerator $generator): bool
    {
        if ($this->readWaitUntil === null || $generator->getSchedule()->shouldRestart()) {
            return true;
        }

        $waitUntil = ($this->readWaitUntil)($generator);

        if ($waitUntil === null) {
            // The schedule ran out of recurring messages; the generator returns immediately.
            return false;
        }

        return ! $waitUntil instanceof DateTimeImmutable || $waitUntil <= $this->clock->now();
    }

    /**
     * When the generator next has a message due, as of the pass it just completed. Null when
     * that is not knowable: the schedule has nothing left, or the pass did not complete (its
     * lock is held by another instance). Uncertain timing retries after one second.
     */
    private function nextRunAt(MessageGenerator $generator): ?DateTimeImmutable
    {
        if ($this->readWaitUntil === null) {
            return $this->clock->now()->modify('+1 second');
        }

        $waitUntil = ($this->readWaitUntil)($generator);

        if ($waitUntil === null) {
            return null;
        }

        return $waitUntil instanceof DateTimeImmutable && $waitUntil > $this->clock->now()
            ? $waitUntil
            : $this->clock->now()->modify('+1 second');
    }

    /**
     * @return list<MessageGenerator>
     */
    private function generators(): array
    {
        if ($this->generators !== null) {
            return $this->generators;
        }

        $generators = [];
        $position = 0;

        foreach ($this->scheduleProviders as $key => $scheduleProvider) {
            // The same name Symfony's own scheduler transport gives the schedule, so listeners see
            // the real schedule name in the message context and the checkpoint is the one a
            // `messenger:consume scheduler_<name>` worker would use.
            $name = is_string($key) ? $key : 'schedule_' . $position;
            ++$position;

            // Resolved once and handed to the generator as its provider (a Schedule is its own
            // provider): application providers typically build a new Schedule on every call.
            $generators[] = new MessageGenerator($scheduleProvider->getSchedule(), $name, $this->clock);
        }

        return $this->generators = $generators;
    }
}
