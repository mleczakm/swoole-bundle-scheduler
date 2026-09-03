<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\Heartbeat;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

#[Group('unit')]
final class SchedulerHeartbeatTest extends TestCase
{
    public function testReturnsNullWhenNoTickHasBeenRecorded(): void
    {
        $heartbeat = new SchedulerHeartbeat(
            new ArrayAdapter(),
            new MockClock(),
            self::createStub(LoggerInterface::class),
        );

        self::assertNull($heartbeat->secondsSinceLastBeat());
    }

    public function testMeasuresElapsedSecondsSinceTheLastBeat(): void
    {
        $clock = new MockClock('2026-09-01 12:00:00');
        $heartbeat = new SchedulerHeartbeat(
            new ArrayAdapter(),
            $clock,
            self::createStub(LoggerInterface::class),
        );

        $heartbeat->beat();
        self::assertSame(0, $heartbeat->secondsSinceLastBeat());

        $clock->sleep(47);
        self::assertSame(47, $heartbeat->secondsSinceLastBeat());
    }

    public function testBeatSwallowsAndLogsCacheFailures(): void
    {
        $cache = self::createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('cache down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to write scheduler heartbeat', self::anything());

        $heartbeat = new SchedulerHeartbeat($cache, new MockClock(), $logger);

        $heartbeat->beat(); // must not throw
    }

    public function testSecondsSinceLastBeatReturnsNullWhenTheCacheReadFails(): void
    {
        $cache = self::createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new RuntimeException('cache down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects(self::once())
            ->method('warning')
            ->with('Failed to read scheduler heartbeat', self::anything());

        $heartbeat = new SchedulerHeartbeat($cache, new MockClock(), $logger);

        self::assertNull($heartbeat->secondsSinceLastBeat());
    }

    public function testHonoursACustomCacheKey(): void
    {
        $clock = new MockClock('2026-09-01 12:00:00');
        $pool = new ArrayAdapter();

        $heartbeat = new SchedulerHeartbeat($pool, $clock, null, 'custom_key');
        $heartbeat->beat();

        self::assertSame($clock->now()->getTimestamp(), $pool->getItem('custom_key')->get());
    }
}
