# Production hardening

The bare polling loop (`enabled` + `interval`) is enough for most apps. The four opt-in
blocks below each address a specific failure mode seen in production under sustained load.
Turn one on when you have evidence of its failure mode - not pre-emptively.

## `lock` - cross-process double dispatch

**Symptom.** Every due message (and every side effect it has - notification emails, payment
captures) fires twice, a few hundred microseconds apart.

**Cause.** `configure()` and `Timer::tick` register once, in the master process. But during
`Server::reload()` - triggered by an HMR file watcher in dev, or by `worker_max_request`
recycles under load - the same timer *also* fires in Swoole's **manager process**, which has
its own copy of the configurator object and its own `$running` flag. The in-process
reentrancy guard can't see across processes; both ticks call `Scheduler::run()`.

**Fix.** `lock.enabled: true` wraps each tick in a `Symfony\Component\Lock\LockFactory`
lock. Back it with a kernel-level store visible to every process:

```yaml
# config/packages/lock.yaml
framework:
    lock: semaphore   # or 'flock', or a Redis DSN
```

```yaml
swoole_bundle_scheduler:
    lock:
        enabled: true
        factory: lock.factory
        resource: app-scheduler-tick
```

## `watchdog` - a hung run wedging the scheduler forever

**Symptom.** No scheduled message runs again, indefinitely, yet the process is up and every
`/health` probe passes. Nothing restarts it.

**Cause.** A `Scheduler::run()` pass that never returns - e.g. a bare `SELECT 1` on a pooled
connection that just never comes back. A SysV semaphore is **not** released when the
coroutine holding it dies, so `$running` stays true and the lock stays held; every later
tick short-circuits.

**Fix.** `watchdog.enabled: true` arms a `Timer::after` deadline for each run. Past the
timeout, this tick is abandoned - `$running` cleared, lock force-released - and the next tick
proceeds. The stuck `run()` is left to finish or not on its own; a generation counter makes
its eventual `finally` a no-op so nothing double-releases.

```yaml
swoole_bundle_scheduler:
    watchdog:
        enabled: true
        timeout: 15   # keep well under health_check.max_silence so a one-off hang self-heals
```

## `heartbeat` + `health_check` - making a wedged scheduler visible

**Symptom.** Same as the watchdog case - scheduler silently dead - but you want an
orchestrator to *act* on it (restart the container) rather than just recover the next tick.

**Fix.** `heartbeat.enabled: true` writes a "last completed tick" timestamp to a
cross-process cache pool after every successful `run()`. `health_check.enabled: true`
(requires `macpaw/symfony-health-check-bundle`, and implies `heartbeat`) registers a check
that fails - returning 503 - once that timestamp is older than `max_silence` seconds:

```yaml
swoole_bundle_scheduler:
    heartbeat:
        enabled: true
        cache: cache.app        # MUST be cross-process + deploy-surviving (Redis / DBAL), not array
    health_check:
        enabled: true
        max_silence: 90
```

```yaml
# config/packages/symfony_health_check.yaml
symfony_health_check:
    health_error_response_code: 503
    health_checks:
        - id: SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck
```

The check stays green until the *first* tick completes, so it never flaps during boot.

## `pre_run` / `after_tick` - app-specific hooks

- `pre_run`: a callable run before each poll pass and before each message dispatch. Use for a
  cheap, idempotent readiness check (connection liveness) - not for real work.
- `after_tick`: a callable run after every tick, success or failure. Use to reset app state
  that isn't tied to a request/message boundary the swoole-bundle already resets - a shared,
  non-pooled service that would otherwise accumulate for the life of the server.

Both take a service id; the service must be invokable (`__invoke`).
