# 0.3.0 — 2026-11-15

- Start scheduling after fork in HTTP worker #0 with adaptive one-shot timers.
- Keep reload-overlapping passes exclusive using shared atomic ownership.
- Replace cache heartbeats with shared memory and expected-pass health.
- Preserve pooled resets, dispatch events and exception retry; return synchronous handler results.
- Watchdog reports stalls without overlapping generator execution.
- Remove lock/heartbeat/after_tick configuration; use named schedule checkpoints.
- Add reproducible Kiddo comparison lab. See docs/usage.md for breaking changes.

# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Support `swoole-bundle/swoole-bundle` versions greater than 0.33 and below 1.0; express
  Symfony 7.4 through 8.x compatibility as one continuous version range.

### Added

- Initial release. Extracted from an app-local implementation into a reusable bundle.
- `SwooleBundle\Scheduler\Scheduler\Scheduler` interface + `DefaultScheduler` - polls every
  `scheduler.schedule_provider` and dispatches due messages through the message bus with
  `PreRunEvent` / `PostRunEvent` / `FailureEvent`.
- `SwooleBundle\Scheduler\Swoole\WithScheduler` - a swoole-bundle server configurator that
  drives the poll on a `Swoole\Timer::tick()` loop, with opt-in cross-process lock, watchdog
  timeout, heartbeat, and `after_tick` hook.
- `SwooleBundle\Scheduler\Heartbeat\SchedulerHeartbeat` and
  `SwooleBundle\Scheduler\HealthCheck\SchedulerHeartbeatHealthCheck` (the latter only when
  `macpaw/symfony-health-check-bundle` is installed).
- `swoole_bundle_scheduler` bundle configuration for all of the above.
