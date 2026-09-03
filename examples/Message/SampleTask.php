<?php

declare(strict_types=1);

namespace App\Scheduler;

/**
 * A plain message the schedule dispatches through the message bus. Keep it a small,
 * serializable value object - the handler does the work.
 */
final readonly class SampleTask
{
    public function __construct(
        public string $reason = 'tick',
    ) {}
}
