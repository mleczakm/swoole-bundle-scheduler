<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\DependencyInjection;

use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SwooleBundle\Scheduler\Scheduler\DefaultScheduler;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use SwooleBundle\Scheduler\Swoole\WithScheduler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use SymfonyHealthCheckBundle\Check\CheckInterface;

/**
 * @phpstan-type SchedulerConfig array{
 *   enabled: bool,
 *   interval: int,
 *   pre_run: string|null,
 *   after_tick: string|null,
 *   lock: array{enabled: bool, factory: string, resource: string},
 *   watchdog: array{enabled: bool, timeout: int},
 *   heartbeat: array{enabled: bool, cache: string, key: string, ttl: int},
 *   health_check: array{enabled: bool, max_silence: int},
 * }
 */
final class SwooleBundleSchedulerExtension extends Extension
{
    public function getAlias(): string
    {
        return 'swoole_bundle_scheduler';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var SchedulerConfig $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (! $config['enabled']) {
            return;
        }

        if (! class_exists(MessageGenerator::class)) {
            throw new RuntimeException(
                'To use the Swoole scheduler, the "symfony/scheduler" package needs to be installed.',
            );
        }

        if (! interface_exists(MessageBusInterface::class)) {
            throw new RuntimeException(
                'To use the Swoole scheduler, the "symfony/messenger" package needs to be installed and configured.',
            );
        }

        $heartbeatEnabled = $config['heartbeat']['enabled'] || $config['health_check']['enabled'];

        $this->registerScheduler($config, $container);
        $this->registerConfigurator($config, $container, $heartbeatEnabled);

        if ($heartbeatEnabled) {
            $this->registerHeartbeat($config, $container);
        }

        if ($config['health_check']['enabled']) {
            $this->registerHealthCheck($config, $container);
        }
    }

    /**
     * @param SchedulerConfig $config
     */
    private function registerScheduler(array $config, ContainerBuilder $container): void
    {
        $definition = $container->register(DefaultScheduler::class, DefaultScheduler::class)
            ->setPublic(false)
            ->setArgument('$bus', new Reference(MessageBusInterface::class))
            ->setArgument('$scheduleProviders', new TaggedIteratorArgument('scheduler.schedule_provider'))
            ->setArgument('$dispatcher', new Reference(
                EventDispatcherInterface::class,
                ContainerInterface::NULL_ON_INVALID_REFERENCE,
            ));

        if ($config['pre_run'] !== null) {
            $definition->setArgument('$beforeRun', new Reference($config['pre_run']));
        }

        $container->setAlias(Scheduler::class, DefaultScheduler::class)->setPublic(false);
    }

    /**
     * @param SchedulerConfig $config
     */
    private function registerConfigurator(array $config, ContainerBuilder $container, bool $heartbeatEnabled): void
    {
        $definition = $container->register(WithScheduler::class, WithScheduler::class)
            ->setPublic(false)
            ->setArgument('$scheduler', new Reference(Scheduler::class))
            ->setArgument('$coWrapper', new Reference(CoWrapper::class))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$intervalMs', $config['interval'] * 1000)
            ->addTag('swoole_bundle.server_configurator');

        if ($config['after_tick'] !== null) {
            $definition->setArgument('$afterTick', new Reference($config['after_tick']));
        }

        if ($config['lock']['enabled']) {
            $definition
                ->setArgument('$lockFactory', new Reference($config['lock']['factory']))
                ->setArgument('$lockResource', $config['lock']['resource']);
        }

        if ($heartbeatEnabled) {
            $definition->setArgument('$heartbeat', new Reference(SchedulerHeartbeat::class));
        }

        if ($config['watchdog']['enabled']) {
            $definition->setArgument('$watchdogTimeoutSeconds', $config['watchdog']['timeout']);
        }
    }

    /**
     * @param SchedulerConfig $config
     */
    private function registerHeartbeat(array $config, ContainerBuilder $container): void
    {
        $container->register(SchedulerHeartbeat::class, SchedulerHeartbeat::class)
            ->setPublic(false)
            ->setArgument('$cache', new Reference($config['heartbeat']['cache']))
            ->setArgument('$clock', new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$cacheKey', $config['heartbeat']['key'])
            ->setArgument('$ttlSeconds', $config['heartbeat']['ttl']);
    }

    /**
     * @param SchedulerConfig $config
     */
    private function registerHealthCheck(array $config, ContainerBuilder $container): void
    {
        if (! interface_exists(CheckInterface::class)) {
            throw new RuntimeException(
                'swoole_bundle_scheduler.health_check is enabled but "macpaw/symfony-health-check-bundle" is not installed.',
            );
        }

        // Public: symfony-health-check-bundle resolves registered checks from the container by id.
        $container->register(SchedulerHeartbeatHealthCheck::class, SchedulerHeartbeatHealthCheck::class)
            ->setPublic(true)
            ->setArgument('$heartbeat', new Reference(SchedulerHeartbeat::class))
            ->setArgument('$maxSilenceSeconds', $config['health_check']['max_silence']);
    }
}
