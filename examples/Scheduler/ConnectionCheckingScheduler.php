<?php

declare(strict_types=1);

namespace App\Scheduler;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use SwooleBundle\Scheduler\Scheduler\DefaultScheduler;
use SwooleBundle\Scheduler\Scheduler\Scheduler;
use Throwable;

/**
 * Replaces the bundle's own {@see Scheduler} with one that pings the database before delegating
 * to {@see DefaultScheduler}. Bind it in config/services.yaml:
 *
 * ```yaml
 * SwooleBundle\Scheduler\Scheduler\Scheduler:
 *     class: App\Scheduler\ConnectionCheckingScheduler
 *     arguments:
 *         $inner: '@SwooleBundle\Scheduler\Scheduler\DefaultScheduler'
 * ```
 *
 * For the common "just run a callable before each poll" case, prefer the built-in
 * `swoole_bundle_scheduler.pre_run` option instead of a whole replacement class.
 */
final readonly class ConnectionCheckingScheduler implements Scheduler
{
    public function __construct(
        private Scheduler $inner,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        try {
            $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable $e) {
            // Force a reconnect on the next query rather than letting a stale pooled
            // connection surface mid-checkpoint-read.
            $this->connection->close();
            $this->logger->warning('Scheduler DB liveness check failed; reconnecting', ['exception' => $e]);
        }

        $this->inner->run();
    }
}
