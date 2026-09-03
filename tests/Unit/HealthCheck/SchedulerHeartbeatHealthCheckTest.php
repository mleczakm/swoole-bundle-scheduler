<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\HealthCheck;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\MockClock;

#[Group('unit')]
final class SchedulerHeartbeatHealthCheckTest extends TestCase
{
    private MockClock $clock;

    private SchedulerHeartbeat $heartbeat;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-01 12:00:00');
        $this->heartbeat = new SchedulerHeartbeat(
            new Psr16Cache(new ArrayAdapter()),
            $this->clock,
            new NullLogger(),
        );
    }

    public function testPassesButFlagsWhenNoTickRecordedYet(): void
    {
        $response = (new SchedulerHeartbeatHealthCheck($this->heartbeat, 90))->check();

        self::assertTrue($response->getResult());
        self::assertSame('scheduler_heartbeat', $response->getName());
        self::assertNull($response->getParams()['seconds_since_last_tick']);
    }

    public function testPassesWhileTicksAreRecent(): void
    {
        $this->heartbeat->beat();
        $this->clock->sleep(30);

        $response = (new SchedulerHeartbeatHealthCheck($this->heartbeat, 90))->check();

        self::assertTrue($response->getResult());
        self::assertSame(30, $response->getParams()['seconds_since_last_tick']);
        self::assertStringContainsString('ticking', $response->getMessage());
    }

    public function testFailsWhenTheLastTickIsOlderThanTheThreshold(): void
    {
        $this->heartbeat->beat();
        $this->clock->sleep(120);

        $response = (new SchedulerHeartbeatHealthCheck($this->heartbeat, 90))->check();

        self::assertFalse($response->getResult());
        self::assertStringContainsString('exceeds threshold 90', $response->getMessage());
        self::assertSame(120, $response->getParams()['seconds_since_last_tick']);
    }
}
