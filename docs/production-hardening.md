# Failure handling

The scheduler owns one active pass per server. Worker #0 creates timers after fork.
A shared atomic PID owner also prevents the replacement worker entering while its
predecessor is still finishing during asynchronous reload. If the owner exits, another
worker can reclaim ownership. This is local coordination, not a distributed lock.

An exception is logged and retried after 1, 2, 4 ... seconds, capped at interval.
A thrown message can already have advanced Symfony's checkpoint; retrying the pass
does not promise delivery retries. Configure Messenger retries and idempotent handlers.

A slow pass is never run concurrently with another pass. The optional watchdog logs
after timeout. If I/O later returns, normal scheduling resumes. If it never returns,
health becomes overdue and an external supervisor must recover the process.
A blocked OS thread also blocks timer callbacks: use I/O timeouts and coroutine-compatible
clients. Do not interpret an absent watchdog log as proof of health.

Heartbeat uses shared atomic memory allocated before fork. It adds no database writes.
The HTTP health endpoint can still block in other application checks while the database
is unavailable; configure request/probe timeouts and test the full endpoint.

CoWrapper releases pooled services at every coroutine boundary. Keep this enabled.
Application-specific pre_run checks remain available, but should be justified by a
reproduced stale-connection failure. LockRegistry is no longer globally modified.

For multiple servers, use Symfony Schedule::lock() backed by a common store. Configure
a lock lifetime suitable for the longest pass. Local ownership and health are scoped
to one Swoole server.
