<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Scheduler;

use DateTimeImmutable;

interface Scheduler
{
    /**
     * Dispatches every message that is due right now.
     *
     * @return DateTimeImmutable|null when the next message becomes due, or null when that is not
     *   known (no recurring messages, or a schedule's lock is held elsewhere) - the caller then
     *   simply comes back after its own maximum interval
     */
    public function run(): ?DateTimeImmutable;
}
