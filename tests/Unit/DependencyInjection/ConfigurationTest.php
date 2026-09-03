<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SwooleBundle\Scheduler\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[Group('unit')]
final class ConfigurationTest extends TestCase
{
    public function testDefaultsAreDisabledAndConservative(): void
    {
        $config = self::process([]);

        self::assertFalse($config['enabled']);
        self::assertSame(60, $config['interval']);
        self::assertNull($config['pre_run']);
        self::assertNull($config['after_tick']);
        self::assertFalse($config['lock']['enabled']);
        self::assertSame('lock.factory', $config['lock']['factory']);
        self::assertSame('swoole-scheduler-tick', $config['lock']['resource']);
        self::assertFalse($config['watchdog']['enabled']);
        self::assertSame(15, $config['watchdog']['timeout']);
        self::assertFalse($config['heartbeat']['enabled']);
        self::assertSame('cache.app', $config['heartbeat']['cache']);
        self::assertFalse($config['health_check']['enabled']);
        self::assertSame(90, $config['health_check']['max_silence']);
    }

    public function testShorthandBooleanEnablesTheBundle(): void
    {
        self::assertTrue(self::process(true)['enabled']);
        self::assertFalse(self::process(false)['enabled']);
    }

    public function testIntervalMustBeAtLeastOneSecond(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        self::process(['interval' => 0]);
    }

    public function testFullConfigRoundTrips(): void
    {
        $config = self::process([
            'enabled' => true,
            'interval' => 1,
            'pre_run' => 'App\Scheduler\ConnectionGuard',
            'after_tick' => 'App\Scheduler\StateReset',
            'lock' => ['enabled' => true, 'factory' => 'app.lock.factory', 'resource' => 'app-scheduler-tick'],
            'watchdog' => ['enabled' => true, 'timeout' => 20],
            'heartbeat' => ['enabled' => true, 'cache' => 'app.cache.scheduler', 'key' => 'k', 'ttl' => 100],
            'health_check' => ['enabled' => true, 'max_silence' => 120],
        ]);

        self::assertTrue($config['enabled']);
        self::assertSame(1, $config['interval']);
        self::assertSame('App\Scheduler\ConnectionGuard', $config['pre_run']);
        self::assertSame('app-scheduler-tick', $config['lock']['resource']);
        self::assertSame(20, $config['watchdog']['timeout']);
        self::assertSame('app.cache.scheduler', $config['heartbeat']['cache']);
        self::assertSame(120, $config['health_check']['max_silence']);
    }
    /**
     * @return array<string, mixed>
     */
    private static function process(mixed $input): array
    {
        return (new Processor())->processConfiguration(new Configuration(), ['swoole_bundle_scheduler' => $input]);
    }
}
