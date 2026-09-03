# Usage

## Why this exists

[Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) is normally polled by
running `messenger:consume scheduler_<name>` as a separate, permanently-running worker
process that blocks between polls on a `sleep()`-based wait. That doesn't cooperate with
Swoole's event loop: it needs its own process, its own supervision, restarts, and memory,
outside the Swoole server entirely.

This bundle polls the same schedules from *inside* the Swoole HTTP server, on a
`Swoole\Timer::tick()` callback - no second process to run or supervise. It is disabled by
default and opt-in per app.

## Requirements

- `swoole-bundle/swoole-bundle` with **coroutines enabled** (the tick coroutine reuses the
  bundle's pooled-stateful-service reset machinery).
- `symfony/scheduler` and `symfony/messenger`, with at least one schedule provider.

## Install

```sh
composer require mleczakm/swoole-bundle-scheduler
```

Register the bundle (Symfony Flex does this automatically):

```php
// config/bundles.php
return [
    // ...
    SwooleBundle\Scheduler\SwooleBundleSchedulerBundle::class => ['all' => true],
];
```

## Quickstart

1. Define a schedule provider - a normal Symfony Scheduler class, nothing bundle-specific:

    ```php
    // src/Scheduler/MainSchedule.php
    #[AsSchedule('main')]
    final readonly class MainSchedule implements ScheduleProviderInterface
    {
        public function __construct(private CacheInterface $cache) {}

        public function getSchedule(): Schedule
        {
            return (new Schedule())
                ->stateful($this->cache)
                ->add(RecurringMessage::every('5 minutes', new SampleTask()));
        }
    }
    ```

2. Enable the polling loop:

    ```yaml
    # config/packages/swoole_bundle_scheduler.yaml
    swoole_bundle_scheduler:
        enabled: true
        interval: 60   # seconds between polls, default 60, minimum 1
    ```

That's it. Every `#[AsSchedule]`-tagged provider is polled automatically, the same way
`messenger:consume scheduler_*` would, off the Swoole event loop instead of a separate
process. Restart the server (`bin/console swoole:server:run`) to pick up the change.

## Full configuration reference

```yaml
swoole_bundle_scheduler:
    enabled: false          # register the Timer::tick loop as a server configurator
    interval: 60            # seconds between polls (min 1)
    pre_run: ~              # service id, invoked (callable) before each poll + before each dispatch
    after_tick: ~           # service id, invoked (callable) after every tick, success or not
    lock:
        enabled: false
        factory: lock.factory
        resource: swoole-scheduler-tick
    watchdog:
        enabled: false
        timeout: 15          # seconds; deadline for one Scheduler::run() pass
    heartbeat:
        enabled: false
        cache: cache.app     # PSR-6 pool, cross-process, deploy-surviving
        key: scheduler_last_tick_at
        ttl: 86400
    health_check:
        enabled: false       # needs macpaw/symfony-health-check-bundle; implies heartbeat
        max_silence: 90
```

`swoole_bundle_scheduler: true` is shorthand for `{ enabled: true }`.

See [production-hardening.md](production-hardening.md) for what `lock` / `watchdog` /
`heartbeat` / `health_check` defend against and when to turn them on.

## How it behaves

- Messages are dispatched through the message bus with the same `PreRunEvent` /
  `PostRunEvent` / `FailureEvent` events Symfony's own scheduler worker dispatches, so
  listeners written against those events (a `PreRunEvent` listener that cancels a run, a
  `FailureEvent` listener that swallows a failure) behave identically here.
- A tick that throws is caught, logged (`Scheduler tick failed`), and does **not** crash the
  server - the next tick retries one interval later. Overlapping ticks (a poll still running
  when the next interval elapses) are skipped, not piled up.
- Each tick registers its coroutine for pooled-stateful-service reset via the swoole-bundle's
  `CoWrapper::defer()` - the same mechanism request and message boundaries use - so an entity
  manager, event dispatcher, etc. touched by `Scheduler::run()` is reset between ticks.

## Caveats

- **`Symfony\Component\Cache\LockRegistry` file locking is disabled app-wide** while this is
  enabled (`LockRegistry::setFiles([])`). Symfony Scheduler's stateful
  `Schedule::stateful()` / `Checkpoint::save()` routes every tick through it, and its
  `flock()`-based locks have been observed to wedge forever under a `Timer::tick` coroutine.
  This trades Symfony Cache's cross-process cache-stampede protection - for **every** pool in
  the app, since `LockRegistry` state is static - for the scheduler not hanging. Acceptable
  because a `Timer::tick` is the only writer of its own checkpoint, so there was no real
  stampede to protect against; and the protection only guards redundant recomputation on a
  cache-miss race, not correctness.
- During `Server::reload()` the tick callback can fire once in Swoole's **manager process**
  with no coroutine underneath it (`Coroutine::getCid() === -1`). The bundle detects this,
  logs a warning, and skips pooled-service reset for that one tick rather than calling
  `CoWrapper::defer()` (which would throw in a way that bypasses the tick's own `catch` and
  kill the manager process).

## Swapping the scheduler implementation

To run something before schedules are polled on every tick, prefer `pre_run` (a callable
service id). For anything more involved, implement
`SwooleBundle\Scheduler\Scheduler\Scheduler` yourself and register it under that id:

```yaml
# config/services.yaml
SwooleBundle\Scheduler\Scheduler\Scheduler:
    class: App\Scheduler\ConnectionCheckingScheduler
    arguments:
        $inner: '@SwooleBundle\Scheduler\Scheduler\DefaultScheduler'
```

See [`examples/Scheduler/ConnectionCheckingScheduler.php`](../examples/Scheduler/ConnectionCheckingScheduler.php).
