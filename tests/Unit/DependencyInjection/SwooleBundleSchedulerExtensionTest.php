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
use SwooleBundle\Scheduler\Swoole\SchedulerLoop;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

#[Group('unit')]
final class SwooleBundleSchedulerExtensionTest extends TestCase
{
    public function testDisabledByDefaultRegistersNothing(): void
    {
        $container = self::load([]);

        self::assertFalse($container->hasDefinition(SchedulerLoop::class));
        self::assertFalse($container->hasDefinition(DefaultScheduler::class));
    }

    public function testEnabledRegistersTheConfiguratorTaggedForSwooleBundle(): void
    {
        $container = self::load(['enabled' => true, 'interval' => 30]);

        self::assertTrue($container->hasDefinition(DefaultScheduler::class));
        self::assertTrue($container->hasAlias(Scheduler::class));

        $withScheduler = $container->getDefinition(SchedulerLoop::class);
        self::assertArrayHasKey('swoole_bundle.server_configurator', $withScheduler->getTags());
        self::assertSame(30_000, $withScheduler->getArgument('$intervalMs'));
        self::assertArrayHasKey('kernel.event_subscriber', $withScheduler->getTags());
        self::assertArrayNotHasKey('$watchdogTimeoutSeconds', $withScheduler->getArguments());
    }

    public function testPreRunAndAfterTickAreWiredAsReferences(): void
    {
        $container = self::load([
            'enabled' => true,
            'pre_run' => 'app.guard',
        ]);

        self::assertEquals(new Reference('app.guard'), $container->getDefinition(DefaultScheduler::class)->getArgument('$beforeRun'));
    }

    public function testLockAndWatchdogWiringIsOptIn(): void
    {
        $container = self::load([
            'enabled' => true,
            'watchdog' => ['enabled' => true, 'timeout' => 20],
        ]);

        $withScheduler = $container->getDefinition(SchedulerLoop::class);
        self::assertSame(20, $withScheduler->getArgument('$watchdogTimeoutSeconds'));
    }

    public function testHealthCheckImpliesHeartbeatAndRegistersAPublicCheck(): void
    {
        $container = self::load([
            'enabled' => true,
            'health_check' => ['enabled' => true, 'max_silence' => 120],
        ]);

        self::assertTrue($container->hasDefinition(SchedulerHeartbeat::class));

        $withScheduler = $container->getDefinition(SchedulerLoop::class);
        self::assertEquals(new Reference(SchedulerHeartbeat::class), $withScheduler->getArgument('$heartbeat'));

        $check = $container->getDefinition(SchedulerHeartbeatHealthCheck::class);
        self::assertTrue($check->isPublic());
        self::assertSame(120, $check->getArgument('$maxSilenceSeconds'));
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
