<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SwooleBundle\Scheduler\DependencyInjection\SwooleBundleSchedulerExtension;
use SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SwooleBundle\Scheduler\Scheduler\DefaultScheduler;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use SwooleBundle\Scheduler\Swoole\WithScheduler;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[Group('unit')]
final class SwooleBundleSchedulerExtensionTest extends TestCase
{
    public function testDisabledByDefaultRegistersNothing(): void
    {
        $container = self::load([]);

        self::assertFalse($container->hasDefinition(WithScheduler::class));
        self::assertFalse($container->hasDefinition(DefaultScheduler::class));
    }

    public function testEnabledRegistersTheConfiguratorTaggedForSwooleBundle(): void
    {
        $container = self::load(['enabled' => true, 'interval' => 30]);

        self::assertTrue($container->hasDefinition(DefaultScheduler::class));
        self::assertTrue($container->hasAlias(Scheduler::class));

        $withScheduler = $container->getDefinition(WithScheduler::class);
        self::assertArrayHasKey('swoole_bundle.server_configurator', $withScheduler->getTags());
        self::assertSame(30_000, $withScheduler->getArgument('$intervalMs'));
        self::assertArrayNotHasKey('$lockFactory', $withScheduler->getArguments());
        self::assertArrayNotHasKey('$heartbeat', $withScheduler->getArguments());
        self::assertArrayNotHasKey('$watchdogTimeoutSeconds', $withScheduler->getArguments());
    }

    public function testPreRunAndAfterTickAreWiredAsReferences(): void
    {
        $container = self::load([
            'enabled' => true,
            'pre_run' => 'app.guard',
            'after_tick' => 'app.reset',
        ]);

        self::assertEquals(new Reference('app.guard'), $container->getDefinition(DefaultScheduler::class)->getArgument('$beforeRun'));
        self::assertEquals(new Reference('app.reset'), $container->getDefinition(WithScheduler::class)->getArgument('$afterTick'));
    }

    public function testLockAndWatchdogWiringIsOptIn(): void
    {
        $container = self::load([
            'enabled' => true,
            'lock' => ['enabled' => true, 'factory' => 'app.lock.factory', 'resource' => 'app-scheduler-tick'],
            'watchdog' => ['enabled' => true, 'timeout' => 20],
        ]);

        $withScheduler = $container->getDefinition(WithScheduler::class);
        self::assertEquals(new Reference('app.lock.factory'), $withScheduler->getArgument('$lockFactory'));
        self::assertSame('app-scheduler-tick', $withScheduler->getArgument('$lockResource'));
        self::assertSame(20, $withScheduler->getArgument('$watchdogTimeoutSeconds'));
    }

    public function testHealthCheckImpliesHeartbeatAndRegistersAPublicCheck(): void
    {
        $container = self::load([
            'enabled' => true,
            'health_check' => ['enabled' => true, 'max_silence' => 120],
        ]);

        self::assertTrue($container->hasDefinition(SchedulerHeartbeat::class));

        $withScheduler = $container->getDefinition(WithScheduler::class);
        self::assertEquals(new Reference(SchedulerHeartbeat::class), $withScheduler->getArgument('$heartbeat'));

        $check = $container->getDefinition(SchedulerHeartbeatHealthCheck::class);
        self::assertTrue($check->isPublic());
        self::assertSame(120, $check->getArgument('$maxSilenceSeconds'));
    }

    public function testHeartbeatCanBeEnabledWithoutTheHealthCheck(): void
    {
        $container = self::load([
            'enabled' => true,
            'heartbeat' => ['enabled' => true, 'cache' => 'app.cache.scheduler', 'key' => 'k', 'ttl' => 100],
        ]);

        self::assertTrue($container->hasDefinition(SchedulerHeartbeat::class));
        self::assertFalse($container->hasDefinition(SchedulerHeartbeatHealthCheck::class));

        $heartbeat = $container->getDefinition(SchedulerHeartbeat::class);
        self::assertEquals(new Reference('app.cache.scheduler'), $heartbeat->getArgument('$cache'));
        self::assertSame('k', $heartbeat->getArgument('$cacheKey'));
        self::assertSame(100, $heartbeat->getArgument('$ttlSeconds'));
    }
    /**
     * @param array<string, mixed> $config
     */
    private static function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new SwooleBundleSchedulerExtension())->load([$config], $container);

        return $container;
    }
}
