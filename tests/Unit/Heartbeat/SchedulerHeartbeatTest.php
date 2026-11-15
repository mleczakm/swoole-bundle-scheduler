<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\Heartbeat;

use PHPUnit\Framework\TestCase;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use Symfony\Component\Clock\MockClock;

final class SchedulerHeartbeatTest extends TestCase
{
    public function testExpectedTimeAllowsIdleSleepButDetectsStalls(): void
    {
        $clock = new MockClock('2026-01-01');
        $heartbeat = new SchedulerHeartbeat($clock);
        self::assertNull($heartbeat->secondsOverdue());
        $heartbeat->expect($clock->now()->modify('+60 seconds'));
        $heartbeat->beat();
        $clock->sleep(50);
        self::assertSame(0, $heartbeat->secondsOverdue());
        self::assertSame(50, $heartbeat->secondsSinceLastBeat());
        $clock->sleep(30);
        self::assertSame(20, $heartbeat->secondsOverdue());
    }

    public function testOwnershipCannotBeReentered(): void
    {
        $heartbeat = new SchedulerHeartbeat();
        self::assertTrue($heartbeat->acquire());
        self::assertFalse($heartbeat->acquire());
        $heartbeat->release();
        self::assertTrue($heartbeat->acquire());
        $heartbeat->release();
    }

    public function testDeadWorkerOwnershipCanBeReclaimed(): void
    {
        $heartbeat = new SchedulerHeartbeat();
        $process = new \Swoole\Process(static function () use ($heartbeat): void {
            $heartbeat->acquire();
        });
        $process->start();
        \Swoole\Process::wait();
        self::assertTrue($heartbeat->acquire());
        $heartbeat->release();
    }

    public function testHeartbeatIsSharedAcrossForks(): void
    {
        $heartbeat = new SchedulerHeartbeat();
        $process = new \Swoole\Process(static function () use ($heartbeat): void {
            $heartbeat->beat();
        });
        $process->start();
        \Swoole\Process::wait();
        self::assertNotNull($heartbeat->secondsSinceLastBeat());
    }
}
