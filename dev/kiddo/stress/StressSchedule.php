<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Lab;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Lab only (dev/kiddo/lab up --stress): a stateful schedule with a message due every second, so
 * the checkpoint is read and saved through the application's cache pool once a second instead
 * of a few times a minute.
 */
#[AsSchedule('lab_stress')]
final readonly class StressSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 second', new StressTick()));
    }
}
