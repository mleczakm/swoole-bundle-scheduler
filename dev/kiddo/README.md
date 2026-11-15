# Kiddo investigation and local lab

Run from the bundle checkout. Requires sibling ../kiddo (or KIDDO_DIR), Docker Compose,
kiddo's built PHP image/vendor dependencies, Bash, curl, jq, patch, Python for optional
analysis, and column. This uses Kiddo's real MainSchedule and local database: messages
and configured external integrations really execute. Use local test data and credentials.

```sh
dev/kiddo/lab up --interval=60 --trace
dev/kiddo/lab health
dev/kiddo/lab reload
dev/kiddo/lab logs
dev/kiddo/lab measure 30
dev/kiddo/lab restore
```

The lab snapshots src/ and overlays the vendored bundle and config. Source edits require
another up. It clears Kiddo's compiled cache, replaces the php container, and starts db.
restore removes the lab php container and rebuilds the original cache; start plain Kiddo
afterward with its normal docker compose up command. No tracked Kiddo files are edited.

For v0.2 comparison: up --ref=v0.2.0 --trace.
For a stateful message every second: up --stress.
Other switches: --bare, --no-pre-run, --no-watchdog, --watchdog-timeout=N,
--max-silence=N, --without=pool-reset, --without=tick-catch.
Old-version patches additionally remove lockregistry-reset, overlap-guard, cid-guard;
--no-lock is meaningful only on v0.2.
Run lab check to validate patches.

## Findings independently checked on 2026-11-15

- v0.2 after reload: pid 1 continues ticking while manager pid 9 also ticks with cid=-1.
  Reproduced from the v0.2.0 tag in Kiddo, consistent with Swoole's manager timer select:
  https://github.com/swoole/swoole-src/blob/v6.2.5/src/server/manager.cc
- Revised design: only worker #0 ticks before/after reload. The manager remains out of
  application code. Shared ownership also covers old/new worker overlap during async reload.
- Paused Kiddo's database for seven seconds with a one-second stress schedule and a
  two-second watchdog. Exactly one scheduler pass entered during the stall; timeout
  logged once, then the active pass returned after unpause and normal scheduling resumed.
  See results/recovery.jsonl. The health probe subsequently passed.
- Automated tests cover timeout non-overlap, exception retry, due-date/readiness behaviour,
  pooled resets, health lateness, shared memory across fork and dead-owner reclamation.
- Earlier Claude experiments reported duplicate sends without the v0.2 lock, manager death
  without its coroutine guard, and a permanent stall after reload plus DB restart.
  These are useful supporting observations, not all independently repeated here.
- Neither a short clean run without LockRegistry modification nor an ordinary DB restart
  proves that all connection/readiness checks are unnecessary.

## Changes to Claude's prototype

The prototype restarted scheduling while a timed-out coroutine still used the same
MessageGenerators. Even with a cap of three abandoned passes, that is unsafe concurrency.
The final watchdog logs the stall and waits. Health plus a supervisor handles permanent
hangs. Recovery after a temporary DB stall was verified in real Kiddo.

Worker #0 alone does not ensure exclusivity: Swoole explicitly allows old and replacement
workers to coexist during async reload. An atomic owner PID allocated before fork protects
the active pass; it is only released on completion, or reclaimed after process death.

Constructor clock references formerly allowed null into non-nullable arguments when the
clock service was missing. Defaults now work without requiring the Framework clock alias.
Tests and examples were updated for the new contract.

## Alternatives considered

| Approach | Tradeoff |
|---|---|
| Timer before Server::start | Rejected: reproduced inherited manager timer and no coroutine |
| Server-start/master timer | Avoids pre-fork timer inheritance, but keeps application work in the master and outside worker reset/recovery boundaries |
| Worker-start one-shot timer | Selected: existing lifecycle events, no extra process, coroutine boundaries, adaptive idle sleep |
| Dedicated Swoole user process | Better workload isolation, but another process/container-service context and separate lifecycle/supervision; worthwhile for blocking handlers |
| messenger:consume scheduler_* | Standard Symfony retry/worker semantics and process isolation; additional persistent worker and memory |
| Long-lived coroutine loop | Possible, but must reset services each iteration and explicitly stop on reload; one-shot chaining gives a clear per-pass boundary |

Adaptive timing currently reads a private Symfony field. This is a documented maintenance
tradeoff, not an ideal public integration API; a public next-run getter is the preferred
upstream solution. Reflection is not used to modify generator state.

## Performance interpretation

measure reports aggregate app workload, not scheduler-only cost. It samples /proc CPU ticks
and PSS/private memory, plus PostgreSQL cumulative transactions/cache writes.
Do not poll /health during measurement: Kiddo's health checks perform DB and HTTP work.
Short runs vary with actual schedule slots and background work. CPU clock ticks assume
Linux USER_HZ=100. Process recycling can make CPU deltas unavailable.

Shared-memory heartbeats eliminate the scheduler's per-tick DB heartbeat writes by
construction. Fewer wakeups and due-only readiness checks reduce idle work. A short
sample is insufficient to establish HTTP latency, sustained-load CPU or memory savings.
See results/ for recorded short-window samples; do not treat them as a benchmark guarantee.
