<?php

declare(strict_types=1);

namespace App\Scheduler;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SampleTaskHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(SampleTask $task): void
    {
        // Runs inside a Swoole Timer::tick coroutine on the HTTP server - the same pooled
        // stateful services an HTTP request sees are available and get reset after the tick.
        $this->logger->info('SampleTask executed', ['reason' => $task->reason]);
    }
}
