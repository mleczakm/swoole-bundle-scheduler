# Usage and migration to 0.3

Register the bundle in config/bundles.php and define schedules using Symfony's
AsSchedule attribute and ScheduleProviderInterface.

## Configuration

```yaml
swoole_bundle_scheduler:
    enabled: true
    interval: 60
    pre_run: null
    watchdog:
        enabled: false
        timeout: 15
    health_check:
        enabled: false
        max_silence: 90
```

The interval (1–86400 seconds) is the maximum idle sleep and retry backoff.
A known earlier due date wakes the loop earlier. Runtime changes to the schedule instance
in the scheduler worker and wall-clock jumps are noticed within this interval.
Changes made in other HTTP workers do not mutate this worker's in-memory schedule.

pre_run is an optional invokable service, called before due checkpoint work and each
message dispatch. Keep it cheap and idempotent. Readiness checks and blocking handlers
can still delay HTTP requests: use coroutine-compatible I/O and async message routing.

The optional watchdog logs an overlong pass. It does **not** abandon, cancel or
concurrently rerun it. The health check reports lateness relative to the expected pass.
A supervisor must restart a permanently unhealthy server; this bundle does not do that.

Health checks require macpaw/symfony-health-check-bundle and explicit registration of
SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck in its checks list.
Configure its failure HTTP status (e.g. 503) separately.

## Breaking changes

- Remove lock, heartbeat and after_tick configuration. Shared-memory heartbeat and
  per-server ownership replace the cache heartbeat and external tick lock.
- Scheduler::run() now returns ?DateTimeImmutable: the next due time, or null when unknown.
  Custom implementations should return a due date or use a suitably short interval.
- WithScheduler is replaced by SchedulerLoop. Service overrides must be updated.
- interval is a maximum sleep, not fixed polling cadence.
- Watchdog timeouts report a stall; they no longer start overlapping passes.
- Schedule checkpoint names now use the AsSchedule name, matching Symfony transports.
  Old schedule_0 checkpoints are not migrated. Plan the cutover: stateful missed-run
  handling may differ on the first start. Do not run both consumer implementations together.
- Heartbeat timestamps do not survive a full server restart; workers share them only
  within one server. Each server instance schedules independently.

## Semantics and compatibility

PreRunEvent can cancel dispatch. PostRunEvent follows successful bus dispatch and includes
a synchronous handler result. FailureEvent concerns dispatch/synchronous handling;
it cannot report a later failure in an asynchronous consumer. Events are not an
exact substitute for Messenger worker lifecycle events.

The adaptive delay reads Symfony MessageGenerator's private waitUntil property through
a bound closure. Symfony currently exposes no usable next-run API; its constructor
accepts CheckpointInterface but its private checkpoint() return type requires the final
Checkpoint class. This is an explicit compatibility risk. If the property disappears,
the default scheduler falls back to one-second polling. Test Symfony upgrades.
A public upstream next-run API should replace this adapter when available.

Use Schedule::lock() with a shared store for multi-server coordination. Per-server
ownership handles overlapping old/new worker processes, not distributed scheduling.
No scheduler can promise exactly-once external side effects across process crashes;
handlers should be idempotent. Stateful schedules preserve checkpoints across reloads;
non-stateful interval schedules restart their timing in the replacement worker.
