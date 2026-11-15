# swoole-bundle-scheduler

Run Symfony Scheduler inside worker #0 of a Swoole HTTP server, without a separate
Messenger consumer. Requires PHP 8.3+, ext-swoole, Symfony 7.4/8.x and
swoole-bundle with coroutines enabled.

Register `SwooleBundle\Scheduler\SwooleBundleSchedulerBundle` in config/bundles.php,
then configure:

```yaml
swoole_bundle_scheduler:
    enabled: true
    interval: 60
```

Normal `#[AsSchedule]` providers are discovered automatically. Messages use the normal
Messenger bus routing; route expensive work to task workers or another async transport.

The loop starts after fork in worker #0. One-shot timers wake at the next due date,
bounded by `interval`. Pooled services are released after each pass. Exceptions are
logged and retried. Shared-memory ownership prevents concurrent passes during worker
reload; the heartbeat uses shared memory instead of database writes.

- [Usage and migration to 0.3](docs/usage.md)
- [Failure handling](docs/production-hardening.md)
- [Kiddo lab and investigation results](dev/kiddo/README.md)

Version 0.3 is a breaking change from 0.2. See the migration notes before upgrading.
