# swoole-bundle-scheduler

Run [Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) **inside** the Swoole
HTTP server, on a `Swoole\Timer::tick()` loop - no separate `messenger:consume scheduler_*`
worker process to run, supervise, restart, or pay memory for.

A companion package to [`swoole-bundle/swoole-bundle`](https://github.com/swoole-bundle/swoole-bundle).

## Install

```sh
composer require mleczakm/swoole-bundle-scheduler
```

```php
// config/bundles.php
SwooleBundle\Scheduler\SwooleBundleSchedulerBundle::class => ['all' => true],
```

```yaml
# config/packages/swoole_bundle_scheduler.yaml
swoole_bundle_scheduler:
    enabled: true
    interval: 60
```

Define a schedule the normal Symfony way (`#[AsSchedule]` + `ScheduleProviderInterface`) and
it is polled automatically. Restart the Swoole server to pick it up.

## What you get

- Polls every `#[AsSchedule]` provider off the Swoole event loop, dispatching due messages
  through the message bus with the same `PreRunEvent` / `PostRunEvent` / `FailureEvent` as
  Symfony's own worker.
- A failed tick is logged and retried next interval - it never crashes the server.
- Overlapping ticks are skipped, not queued.
- Each tick resets the pooled stateful services it touches, via the swoole-bundle's own
  coroutine boundary machinery.

## Opt-in production hardening

All disabled by default; enable per app when you hit the failure mode:

| Option | Defends against |
|---|---|
| `lock` | The tick firing in a second OS process (Swoole manager during reload) and double-dispatching |
| `watchdog` | A hung `Scheduler::run()` wedging the scheduler forever behind a never-freed semaphore |
| `heartbeat` + `health_check` | A wedged scheduler staying invisible while `/health` keeps passing |
| `pre_run` / `after_tick` | App-specific readiness checks / state resets around each tick |

## Docs

- [docs/usage.md](docs/usage.md) - walkthrough, full config reference, caveats
- [docs/production-hardening.md](docs/production-hardening.md) - when and how to enable each hardening option
- [examples/](examples/) - copy-paste schedule, message, handler, config, custom scheduler

## Requirements

- PHP >= 8.3, `ext-swoole`
- `swoole-bundle/swoole-bundle` `>0.33 <1.0` with coroutines enabled
- `symfony/scheduler` + `symfony/messenger`
- `symfony/lock` (only for the `lock` option)
- `macpaw/symfony-health-check-bundle` (only for the `health_check` option)

## License

MIT
