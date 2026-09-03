<?php

declare(strict_types=1);

namespace SwooleBundle\Scheduler\HealthCheck;

use Override;
use SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat;
use SymfonyHealthCheckBundle\Check\CheckInterface;
use SymfonyHealthCheckBundle\Dto\Response;

use function sprintf;

/**
 * Fails when the scheduler has not completed a tick within `maxSilenceSeconds`.
 *
 * The `Timer::tick` coroutine in {@see \SwooleBundle\Scheduler\Swoole\WithScheduler} fires every
 * `interval`; it can wedge inside `Scheduler::run()` so that no scheduled message runs again
 * while every other `/health` check keeps passing and nothing restarts the container. Counting
 * the seconds since the last heartbeat catches that directly and, via the 503 the bundle
 * returns for a failed check, hands an orchestrator / autoheal something to act on in tens of
 * seconds instead of waiting for a memory threshold hours later.
 *
 * Only registered when `macpaw/symfony-health-check-bundle` is installed.
 */
final readonly class SchedulerHeartbeatHealthCheck implements CheckInterface
{
    /**
     * @param int $maxSilenceSeconds seconds without a completed tick above which the check
     *                               fails; with a 1s interval this is tens of missed ticks,
     *                               not a tight bound
     */
    public function __construct(
        private SchedulerHeartbeat $heartbeat,
        private int $maxSilenceSeconds,
    ) {}

    #[Override]
    public function check(): Response
    {
        $secondsSinceLastTick = $this->heartbeat->secondsSinceLastBeat();

        if ($secondsSinceLastTick === null) {
            // No tick recorded yet (fresh boot) - a genuinely stuck boot is already covered
            // by the other checks, so this stays green rather than flapping during startup.
            return new Response('scheduler_heartbeat', true, 'No scheduler tick recorded yet', [
                'seconds_since_last_tick' => null,
                'threshold_seconds' => $this->maxSilenceSeconds,
            ]);
        }

        $params = [
            'seconds_since_last_tick' => $secondsSinceLastTick,
            'threshold_seconds' => $this->maxSilenceSeconds,
        ];

        if ($secondsSinceLastTick > $this->maxSilenceSeconds) {
            return new Response(
                'scheduler_heartbeat',
                false,
                sprintf(
                    'Scheduler last completed a tick %ds ago, exceeds threshold %ds',
                    $secondsSinceLastTick,
                    $this->maxSilenceSeconds,
                ),
                $params,
            );
        }

        return new Response('scheduler_heartbeat', true, 'Scheduler is ticking', $params);
    }
}
