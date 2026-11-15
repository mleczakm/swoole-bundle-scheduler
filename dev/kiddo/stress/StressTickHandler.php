<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\Lab;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class StressTickHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function __invoke(StressTick $message): void
    {
        $this->logger->notice(sprintf('[lab] stress tick handled pid=%d', getmypid()));
    }
}
