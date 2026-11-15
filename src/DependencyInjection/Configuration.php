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
                    ->info('Run Symfony Scheduler schedules inside the Swoole HTTP server (worker #0).')
                    ->defaultFalse()
                    ->treatNullLike(false)
                ->end()
                ->integerNode('interval')
                    ->info(
                        'The longest the scheduler sleeps between passes, in seconds. Due messages wake it '
                        . 'up on time regardless; this only bounds how quickly a schedule modified at runtime '
                        . 'or a wall-clock jump is noticed, and how far failed passes back off.',
                    )
                    ->defaultValue(60)
                    ->min(1)
                    // Swoole timers cannot be armed further than a day ahead.
                    ->max(86_400)
                ->end()
                ->scalarNode('pre_run')
                    ->info(
                        'Optional service id, invoked as a callable before a pass that has due messages '
                        . 'reads its checkpoint, and before each message dispatch (e.g. a database connection '
                        . 'liveness check). Never invoked while nothing is due. Must be invokable.',
                    )
                    ->defaultNull()
                ->end()
                ->arrayNode('watchdog')
                    ->info('Deadline for a single pass; past it a stall is logged; passes never overlap.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('timeout')
                            ->info('Seconds a single pass is allowed before the watchdog logs a stall.')
                            ->defaultValue(15)
                            ->min(1)
                            ->max(86_400)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('health_check')
                    ->info('Register a macpaw/symfony-health-check-bundle check that fails when the scheduler falls behind.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->integerNode('max_silence')
                            ->info('Seconds the scheduler may be behind its expected next pass before the /health check fails.')
                            ->defaultValue(90)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
