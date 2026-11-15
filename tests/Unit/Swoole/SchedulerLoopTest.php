<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Tests\Unit\Swoole;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Timer;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use SwooleBundle\Scheduler\Swoole\SchedulerLoop;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\CoWrapper;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;

use function Swoole\Coroutine\run;

final class SchedulerLoopTest extends TestCase
{
    public function testTimeoutDoesNotOverlapAndCompletionResumes(): void
    {
        $active = 0;
        $peak = 0;
        $calls = 0;
        $scheduler = $this->createStub(Scheduler::class);
        $scheduler->method('run')->willReturnCallback(static function () use (&$active, &$peak, &$calls): ?DateTimeImmutable {
            ++$calls;
            $peak = max($peak, ++$active);
            Coroutine::sleep($calls === 1 ? 1.2 : 0.01);
            --$active;
            return null;
        });
        $loop = new SchedulerLoop($scheduler, self::emptyCoWrapper(), new SchedulerHeartbeat(), intervalMs: 10, watchdogTimeoutSeconds: 1);
        run(static function () use ($loop): void {
            $loop->start();
            Coroutine::sleep(1.35);
            $loop->stop();
            Coroutine::sleep(0.03);
        });
        self::assertSame(1, $peak);
        self::assertGreaterThan(1, $calls);
        self::assertEmpty(iterator_to_array(Timer::list()));
    }

    public function testFailedPassRetriesAndStopClearsTimers(): void
    {
        $calls = 0;
        $scheduler = $this->createStub(Scheduler::class);
        $scheduler->method('run')->willReturnCallback(static function () use (&$calls): ?DateTimeImmutable {
            if (++$calls === 1) {
                throw new RuntimeException('expected failure');
            }
            return null;
        });
        $loop = new SchedulerLoop($scheduler, self::emptyCoWrapper(), new SchedulerHeartbeat(), intervalMs: 10);
        run(static function () use ($loop): void {
            $loop->start();
            Coroutine::sleep(0.08);
            $loop->stop();
        });
        self::assertGreaterThan(1, $calls);
        self::assertEmpty(iterator_to_array(Timer::list()));
    }

    public function testEveryPassReleasesRealPooledServices(): void
    {
        $pool = $this->createMock(\SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePool::class);
        $pool->expects($this->once())->method('releaseFromCoroutine');
        $wrapper = new CoWrapper(new ServicePoolContainer([
            new \SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolEntry($pool),
        ]));
        $loop = new SchedulerLoop(self::createStub(Scheduler::class), $wrapper, new SchedulerHeartbeat());
        run(static function () use ($loop): void {
            $loop->start();
            Coroutine::sleep(0.02);
            $loop->stop();
        });
    }

    public function testFailuresDoNotAdvanceHealthExpectation(): void
    {
        $clock = new \Symfony\Component\Clock\MockClock('2026-01-01');
        $heartbeat = new SchedulerHeartbeat($clock);
        $scheduler = self::createStub(Scheduler::class);
        $scheduler->method('run')->willThrowException(new RuntimeException('failure'));
        $loop = new SchedulerLoop($scheduler, self::emptyCoWrapper(), $heartbeat, clock: $clock, intervalMs: 10);
        run(static function () use ($loop, $clock): void {
            $loop->start();
            $clock->sleep(100);
            Coroutine::sleep(0.05);
            $loop->stop();
        });
        self::assertNull($heartbeat->secondsSinceLastBeat());
        self::assertSame(100, $heartbeat->secondsOverdue());
    }

    public function testOnlyWorkerZeroArmsTimersAfterFork(): void
    {
        run(static function (): void {
            $loop = new SchedulerLoop(self::createStub(Scheduler::class), self::emptyCoWrapper(), new SchedulerHeartbeat());
            $server = self::createStub(\Swoole\Http\Server::class);
            $loop->configure($server);
            self::assertEmpty(iterator_to_array(Timer::list()));
            $loop->onWorkerStarted(new \SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent($server, 1));
            self::assertEmpty(iterator_to_array(Timer::list()));
            $loop->onWorkerStarted(new \SwooleBundle\SwooleBundle\Bridge\Symfony\Event\WorkerStartedEvent($server, 0));
            self::assertNotEmpty(iterator_to_array(Timer::list()));
            $loop->stop();
            self::assertEmpty(iterator_to_array(Timer::list()));
        });
    }

    private static function emptyCoWrapper(): CoWrapper
    {
        return new CoWrapper(new ServicePoolContainer([]));
    }
}
