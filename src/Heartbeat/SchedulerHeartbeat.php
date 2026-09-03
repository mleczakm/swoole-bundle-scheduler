<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Heartbeat;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Throwable;

/**
 * One shared timestamp: "when did the scheduler tick last complete a full pass".
 *
 * Written by {@see \SwooleBundle\Scheduler\Swoole\WithScheduler} after every successful
 * `Scheduler::run()`, read by {@see \SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck}
 * from an HTTP worker. Back it with a cross-process, deploy-surviving PSR-16 store (a Redis or
 * DBAL-backed cache pool), not an in-memory one - the writer and the reader are different
 * processes.
 *
 * A `Timer::tick` coroutine can wedge inside `Scheduler::run()` while every `/health` probe
 * still passes and nothing restarts the container. A stale heartbeat is the direct signal for
 * that.
 */
final readonly class SchedulerHeartbeat
{
    private LoggerInterface $logger;

    public function __construct(
        private CacheInterface $cache,
        private ClockInterface $clock = new Clock(),
        ?LoggerInterface $logger = null,
        private string $cacheKey = 'scheduler_last_tick_at',
        // TTL long enough that a wedged scheduler keeps producing an ever-growing "seconds
        // since" for a full day rather than the key silently expiring back to "unknown".
        private int $ttlSeconds = 86_400,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Best-effort: a flaky cache write must not turn a healthy tick into a "tick failed"
     * error, so this swallows and logs rather than throwing.
     */
    public function beat(): void
    {
        try {
            $this->cache->set($this->cacheKey, $this->clock->now()->getTimestamp(), $this->ttlSeconds);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to write scheduler heartbeat', ['exception' => $e]);
        }
    }

    /**
     * Seconds since the last recorded tick, or null when it is not knowable - none recorded
     * yet (fresh boot), the key expired (scheduler dead longer than the TTL), or the cache
     * read itself failed.
     */
    public function secondsSinceLastBeat(): ?int
    {
        try {
            $lastTickAt = $this->cache->get($this->cacheKey);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to read scheduler heartbeat', ['exception' => $e]);

            return null;
        }

        if (! is_int($lastTickAt)) {
            return null;
        }

        return max(0, $this->clock->now()->getTimestamp() - $lastTickAt);
    }
}
