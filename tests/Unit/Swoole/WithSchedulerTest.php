<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\Swoole;

use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Http\Server;
use Swoole\Timer;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use SwooleBundle\Scheduler\Swoole\WithScheduler;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolEntry;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\LockRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

use function Swoole\Coroutine\run;

#[Group('unit')]
final class WithSchedulerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $originalLockRegistryFiles;

    #[Override]
    protected function setUp(): void
    {
        $this->originalLockRegistryFiles = self::lockRegistryFiles();
    }

    #[Override]
    protected function tearDown(): void
    {
        LockRegistry::setFiles($this->originalLockRegistryFiles);
    }

    public function testConfigureRegistersASwooleTickClearedOnDestruct(): void
    {
        self::assertEmpty(iterator_to_array(Timer::list()));

        $withScheduler = new WithScheduler(
            self::createStub(Scheduler::class),
            self::emptyCoWrapper(),
        );
        $withScheduler->configure(self::createStub(Server::class));

        self::assertNotEmpty(iterator_to_array(Timer::list()));

        $withScheduler->__destruct();

        self::assertEmpty(iterator_to_array(Timer::list()));
    }

    public function testConfigureDisablesLockRegistryFileLocking(): void
    {
        LockRegistry::setFiles(['some/file.php']);

        $withScheduler = new WithScheduler(
            self::createStub(Scheduler::class),
            self::emptyCoWrapper(),
        );
        $withScheduler->configure(self::createStub(Server::class));

        self::assertSame([], self::lockRegistryFiles());

        $withScheduler->__destruct();
    }

    public function testTickRunsScheduler(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('run');

        $withScheduler = new WithScheduler($scheduler, self::emptyCoWrapper());

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });
    }

    public function testTickCallsTheAfterTickHookWhetherOrNotTheRunSucceeded(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->exactly(2))
            ->method('run')
            ->willReturnCallback(static function (): void {
                static $calls = 0;
                ++$calls;

                if ($calls === 1) {
                    throw new RuntimeException('boom');
                }
            });

        $calls = 0;
        $withScheduler = new WithScheduler(
            $scheduler,
            self::emptyCoWrapper(),
            afterTick: static function () use (&$calls): void {
                ++$calls;
            },
        );

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });
        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        self::assertSame(2, $calls);
    }

    public function testTickWritesTheHeartbeatAfterASuccessfulRun(): void
    {
        $clock = new MockClock('2026-09-01 12:00:00');
        $heartbeat = new SchedulerHeartbeat(new ArrayAdapter(), $clock, new NullLogger());

        $withScheduler = new WithScheduler(
            self::createStub(Scheduler::class),
            self::emptyCoWrapper(),
            heartbeat: $heartbeat,
        );

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        self::assertSame(0, $heartbeat->secondsSinceLastBeat());
    }

    public function testTickDoesNotWriteTheHeartbeatWhenTheRunFails(): void
    {
        $clock = new MockClock('2026-09-01 12:00:00');
        $heartbeat = new SchedulerHeartbeat(new ArrayAdapter(), $clock, new NullLogger());

        $scheduler = self::createStub(Scheduler::class);
        $scheduler->method('run')->willThrowException(new RuntimeException('boom'));

        $withScheduler = new WithScheduler(
            $scheduler,
            self::emptyCoWrapper(),
            logger: self::createStub(LoggerInterface::class),
            heartbeat: $heartbeat,
        );

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        self::assertNull($heartbeat->secondsSinceLastBeat());
    }

    public function testTickReleasesPooledServicesForTheCurrentCoroutine(): void
    {
        // Real CoWrapper/ServicePoolContainer instead of mocks: this is exactly the wiring the
        // request- and message-boundary handlers rely on for every HTTP request/async message,
        // so it's worth verifying tick() plugs into the real thing rather than just a stub.
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine');

        $coWrapper = new CoWrapper(new ServicePoolContainer([new ServicePoolEntry($pool)]));

        $withScheduler = new WithScheduler(self::createStub(Scheduler::class), $coWrapper);

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });
    }

    public function testTickSkipsWhileAlreadyRunning(): void
    {
        $scheduler = $this->createMock(Scheduler::class);

        $withScheduler = new WithScheduler($scheduler, self::emptyCoWrapper());

        $scheduler
            ->expects($this->once())
            ->method('run')
            ->willReturnCallback(static function () use ($withScheduler): void {
                // Simulates an overlapping tick firing while this one is still in-flight.
                $withScheduler->tick();
            });

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });
    }

    public function testTickResetsRunningFlagAfterExceptionSoLaterTicksAreNotSkipped(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->expects($this->exactly(2))
            ->method('run')
            ->willReturnCallback(static function (): void {
                static $calls = 0;
                ++$calls;

                if ($calls === 1) {
                    throw new RuntimeException('boom');
                }
            });

        $withScheduler = new WithScheduler($scheduler, self::emptyCoWrapper());

        // A failed tick must not propagate - Timer::tick has no caller to catch it, so an
        // uncaught exception here crashes the entire process.
        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        // A stuck/failed tick must not permanently block future ticks from running.
        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });
    }

    public function testTickLogsAndSwallowsExceptionsFromScheduler(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $exception = new RuntimeException('boom');
        $scheduler->expects($this->once())->method('run')->willThrowException($exception);

        // Asserting via ->with() on a mock invoked from inside Swoole\Coroutine\run() crashes
        // PHPUnit's parameter-matcher (it walks the call stack for the enclosing TestCase, which
        // a coroutine's separate stack doesn't have) - capture the call instead and assert on it
        // from the outer, non-coroutine stack once run() returns.
        $loggedMessage = null;
        $loggedContext = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('error')
            ->willReturnCallback(static function (string $message, array $context) use (
                &$loggedMessage,
                &$loggedContext,
            ): void {
                $loggedMessage = $message;
                $loggedContext = $context;
            });

        $withScheduler = new WithScheduler($scheduler, self::emptyCoWrapper(), logger: $logger);

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        self::assertSame('Scheduler tick failed', $loggedMessage);
        self::assertSame(['exception' => $exception], $loggedContext);
    }

    public function testTickSkipsPooledServiceResetAndLogsAWarningOutsideACoroutine(): void
    {
        // Reproduces the actual failure mode directly, without needing swoole's own reload/
        // recycle timing: calling tick() with no enclosing Coroutine\run() is exactly the
        // condition (Coroutine::getCid() === -1) that CoWrapper::defer() can't handle. This must
        // not call defer() at all in that case, and must not crash this test process doing it.
        $pool = $this->createMock(ServicePool::class);
        $pool->expects($this->never())->method('releaseFromCoroutine');

        $coWrapper = new CoWrapper(new ServicePoolContainer([new ServicePoolEntry($pool)]));

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())->method('run');

        $loggedMessage = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->once())
            ->method('warning')
            ->willReturnCallback(static function (string $message) use (&$loggedMessage): void {
                $loggedMessage = $message;
            });

        $withScheduler = new WithScheduler($scheduler, $coWrapper, logger: $logger);

        // No run() wrapper - this is the whole point of the test.
        $withScheduler->tick();

        self::assertNotNull($loggedMessage);
        self::assertStringContainsString('no coroutine context', $loggedMessage);
    }

    public function testTickSkipsWhileAnotherProcessHoldsTheCrossProcessLock(): void
    {
        // Models the production failure: two separate WithScheduler instances (one per OS
        // process, e.g. Swoole's master and a manager-process tick spillover) sharing only the
        // lock backend, not any in-process state. Without the lock, both ticks' run() calls
        // fired within microseconds of each other.
        $lockFactory = self::lockFactory();

        $second = $this->createMock(Scheduler::class);
        $second->expects($this->never())->method('run');
        $secondWithScheduler = new WithScheduler(
            $second,
            self::emptyCoWrapper(),
            lockFactory: $lockFactory,
        );

        $first = $this->createMock(Scheduler::class);
        $first
            ->expects($this->once())
            ->method('run')
            ->willReturnCallback(static function () use ($secondWithScheduler): void {
                $secondWithScheduler->tick();
            });
        $firstWithScheduler = new WithScheduler(
            $first,
            self::emptyCoWrapper(),
            lockFactory: $lockFactory,
        );

        run(static function () use ($firstWithScheduler): void {
            $firstWithScheduler->tick();
        });
    }

    public function testWatchdogForceReleasesTheLockWhenRunOverrunsTheTimeout(): void
    {
        // The only test here that actually suspends a coroutine (Coroutine::sleep) and drives
        // the Swoole event loop. Doing that while Xdebug's coverage driver is recording reliably
        // segfaults the process on shutdown; every synchronous path is covered elsewhere.
        if (str_contains((string) ini_get('xdebug.mode'), 'coverage')) {
            self::markTestSkipped('Swoole coroutine suspend + Xdebug coverage segfaults on shutdown');
        }

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->expects($this->once())
            ->method('run')
            ->willReturnCallback(static function (): void {
                // Outlasts the 1s timeout below; the Timer::after watchdog fires while this is
                // still yielded and force-releases the lock + $running flag.
                Coroutine::sleep(2);
            });

        $loggedError = null;
        $logger = self::createStub(LoggerInterface::class);
        $logger
            ->method('error')
            ->willReturnCallback(static function (string $message) use (&$loggedError): void {
                $loggedError = $message;
            });

        $lockFactory = self::lockFactory();
        $withScheduler = new WithScheduler(
            $scheduler,
            self::emptyCoWrapper(),
            logger: $logger,
            lockFactory: $lockFactory,
            lockResource: 'swoole-scheduler-tick',
            watchdogTimeoutSeconds: 1,
        );

        run(static function () use ($withScheduler): void {
            $withScheduler->tick();
        });

        self::assertStringContainsString('force-released the lock', (string) $loggedError);
        // The watchdog released the semaphore rather than leaving it held: a fresh acquire of
        // the same resource now succeeds.
        self::assertTrue($lockFactory->createLock('swoole-scheduler-tick')->acquire());
    }

    private static function lockFactory(): LockFactory
    {
        return new LockFactory(new InMemoryStore());
    }

    private static function emptyCoWrapper(): CoWrapper
    {
        return new CoWrapper(new ServicePoolContainer([]));
    }

    /**
     * @return list<string>
     */
    private static function lockRegistryFiles(): array
    {
        $property = new ReflectionClass(LockRegistry::class)->getProperty('files');

        /** @var list<string> $files */
        $files = $property->getValue();

        return $files;
    }
}
