<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\HealthCheck;

use Override;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SymfonyHealthCheckBundle\Check\CheckInterface;
use SymfonyHealthCheckBundle\Dto\Response;

use function sprintf;

/**
 * Fails when the scheduler is more than `maxSilenceSeconds` behind.
 *
 * A pass can wedge inside `Scheduler::run()` so that no scheduled message runs again while every
 * other `/health` check keeps passing and nothing restarts the container. The loop records when
 * its next pass is expected; this check fails once that moment is `maxSilenceSeconds` in the
 * past, and via the 503 the health-check bundle returns hands an orchestrator / autoheal
 * something to act on in tens of seconds instead of a memory threshold hours later.
 *
 * Only registered when `macpaw/symfony-health-check-bundle` is installed.
 */
final readonly class SchedulerHeartbeatHealthCheck implements CheckInterface
{
    /**
     * @param int $maxSilenceSeconds how far behind its expected next pass the scheduler may be
     *                               before the check fails. Independent of the loop interval and
     *                               of how sparse the schedule is; it only has to exceed the
     *                               longest pass you consider normal.
     */
    public function __construct(
        private SchedulerHeartbeat $heartbeat,
        private int $maxSilenceSeconds,
    ) {}

    #[Override]
    public function check(): Response
    {
        $secondsOverdue = $this->heartbeat->secondsOverdue();

        $params = [
            'seconds_since_last_tick' => $this->heartbeat->secondsSinceLastBeat(),
            'seconds_overdue' => $secondsOverdue,
            'threshold_seconds' => $this->maxSilenceSeconds,
        ];

        if ($secondsOverdue === null) {
            // The loop has not started in this server yet - a genuinely stuck boot is already
            // covered by the other checks, so this stays green rather than flapping during startup.
            return new Response('scheduler_heartbeat', true, 'Scheduler not started yet', $params);
        }

        if ($secondsOverdue > $this->maxSilenceSeconds) {
            return new Response(
                'scheduler_heartbeat',
                false,
                sprintf(
                    'Scheduler is %ds behind its expected pass, exceeds threshold %ds',
                    $secondsOverdue,
                    $this->maxSilenceSeconds,
                ),
                $params,
            );
        }

        return new Response('scheduler_heartbeat', true, 'Scheduler is ticking', $params);
    }
}
