<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('swoole_bundle_scheduler');

        $treeBuilder->getRootNode()
            ->addDefaultsIfNotSet()
            ->beforeNormalization()
                ->ifTrue(static fn($v): bool => is_bool($v) || $v === null)
                ->then(static fn($v): array => ['enabled' => (bool) $v])
            ->end()
            ->children()
                ->booleanNode('enabled')
                    ->info('Register the Swoole Timer::tick scheduler polling loop as a server configurator.')
                    ->defaultFalse()
                    ->treatNullLike(false)
                ->end()
                ->integerNode('interval')
                    ->info('How often, in seconds, to poll Symfony Scheduler schedules for due messages.')
                    ->defaultValue(60)
                    ->min(1)
                ->end()
                ->scalarNode('pre_run')
                    ->info(
                        'Optional service id, invoked as a callable before each poll pass and before each '
                        . 'message dispatch (e.g. a database connection liveness check). Must be invokable.',
                    )
                    ->defaultNull()
                ->end()
                ->scalarNode('after_tick')
                    ->info(
                        'Optional service id, invoked as a callable after every tick whether it succeeded '
                        . 'or not (e.g. resetting shared, non-pooled app state). Must be invokable.',
                    )
                    ->defaultNull()
                ->end()
                ->arrayNode('lock')
                    ->info('Cross-process lock around each tick, to stop a spillover tick in a second OS process double-dispatching.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('factory')
                            ->info('Service id of a Symfony\Component\Lock\LockFactory backed by a cross-process store.')
                            ->defaultValue('lock.factory')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('resource')
                            ->info('Lock resource name.')
                            ->defaultValue('swoole-scheduler-tick')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('watchdog')
                    ->info('Timer::after deadline for a single Scheduler::run() pass; past it the tick is abandoned and the lock force-released.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('timeout')
                            ->info('Seconds a single Scheduler::run() pass is allowed before the watchdog force-releases the tick.')
                            ->defaultValue(15)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('heartbeat')
                    ->info('Record a "last completed tick" timestamp after every successful run, for an HTTP-worker health check to read.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('cache')
                            ->info('PSR-16 cache service id. Must be a cross-process, deploy-surviving store (Redis / DBAL), not an in-memory pool.')
                            ->defaultValue('cache.app')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('key')->defaultValue('scheduler_last_tick_at')->cannotBeEmpty()->end()
                        ->integerNode('ttl')->defaultValue(86_400)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('health_check')
                    ->info('Register a macpaw/symfony-health-check-bundle check that fails when ticks stop completing. Implies heartbeat.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('max_silence')
                            ->info('Seconds without a completed tick above which the /health check fails.')
                            ->defaultValue(90)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
