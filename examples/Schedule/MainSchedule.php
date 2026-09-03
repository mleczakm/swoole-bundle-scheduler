<?php

declare(strict_types=1);

namespace App\Scheduler;

use DateTimeZone;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Any service tagged #[AsSchedule] is picked up automatically - the bundle polls every
 * `scheduler.schedule_provider` the same way `messenger:consume scheduler_*` would.
 *
 * `->stateful($cache)` persists each recurring message's checkpoint so a missed window is
 * caught up after a restart instead of silently skipped. Back it with a cross-process,
 * deploy-surviving cache pool (Redis / DBAL), not an in-memory one.
 */
#[AsSchedule('main')]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::every('5 minutes', new SampleTask('every 5 minutes')),
                RecurringMessage::every(60, new SampleTask('every minute')),
                RecurringMessage::cron('45 8 * * *', new SampleTask('daily 08:45'), new DateTimeZone('Europe/Warsaw')),
            );
    }
}
