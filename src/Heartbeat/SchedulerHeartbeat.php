<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Heartbeat;

use DateTimeImmutable;
use Swoole\Atomic\Long;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;

/**
 * Two timestamps in shared memory: when the scheduler last completed a pass, and when it is
 * expected to run the next one.
 *
 * Written by {@see \SwooleBundle\Scheduler\Swoole\SchedulerLoop} in the worker that runs the
 * scheduler, read by {@see \SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck}
 * from whichever HTTP worker serves the probe. {@see Long} is anonymous shared memory, so a
 * beat is a single atomic store - no cache pool, no database round trip - and it is visible to
 * every process forked after this object was built. That is why the bundle instantiates it
 * before the server starts; an instance created inside a worker is private to that worker.
 *
 * Nothing survives a server restart, on purpose: a fresh server reports "not started yet"
 * instead of the age of the previous process's last beat.
 */
final readonly class SchedulerHeartbeat
{
    private Long $completedAt;

    private Long $expectedAt;

    private Long $owner;

    public function __construct(
        private ClockInterface $clock = new Clock(),
    ) {
        $this->completedAt = new Long(0);
        $this->expectedAt = new Long(0);
        $this->owner = new Long(0);
    }

    /**
     * Serialize old and new worker #0 during asynchronous reload. A dead owner can be
     * reclaimed; a slow but live owner must finish before another pass enters.
     */
    public function acquire(): bool
    {
        $pid = (int) getmypid();
        $owner = $this->owner->get();
        if ($owner !== 0 && ! \Swoole\Process::kill($owner, 0)) {
            $this->owner->cmpset($owner, 0);
        }

        return $this->owner->cmpset(0, $pid);
    }

    public function release(): void
    {
        $this->owner->cmpset((int) getmypid(), 0);
    }

    /**
     * The loop armed its timer: a pass should start at $at.
     */
    public function expect(DateTimeImmutable $at): void
    {
        $this->expectedAt->set($at->getTimestamp());
    }

    /**
     * A pass completed.
     */
    public function beat(): void
    {
        $this->completedAt->set($this->clock->now()->getTimestamp());
    }

    /**
     * Seconds since the last completed pass, or null when none completed yet.
     */
    public function secondsSinceLastBeat(): ?int
    {
        $completedAt = $this->completedAt->get();

        return $completedAt === 0 ? null : max(0, $this->clock->now()->getTimestamp() - $completedAt);
    }

    /**
     * How long the scheduler has been running behind: seconds since a pass was last expected to
     * start without a newer expectation replacing it. 0 while it is on time - a pass re-arms the
     * timer (and so moves the expectation forward) as soon as it finishes. Null when the loop
     * never started.
     *
     * Measuring against the expected time rather than the last beat keeps this independent of
     * how sparse the schedule is: a loop sleeping an hour until the next message is not late.
     */
    public function secondsOverdue(): ?int
    {
        $expectedAt = $this->expectedAt->get();

        return $expectedAt === 0 ? null : max(0, $this->clock->now()->getTimestamp() - $expectedAt);
    }
}
