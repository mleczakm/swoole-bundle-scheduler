# Verification samples — 2026-11-15

recovery.jsonl: real Kiddo, current design, stateful one-second stress schedule,
interval=60, watchdog=2. Reload, pause DB for seven seconds, unpause.
A single pass spans the stall and emits one timeout; subsequent passes resume.

current-30s.txt: real Kiddo MainSchedule, interval=60, no stress/trace, 30-second
idle sample. Total PSS 149 MiB, private memory 91 MiB, transactions 1.80/s and
cache writes 0.30/s. This includes application work and is not a guarantee.

The attempted v0.2 comparison coincided with container shutdown near its end and
is excluded as an unreliable baseline. Earlier Claude numbers were not reproduced
under a controlled long-running comparison. No CPU, HTTP throughput, or memory
improvement percentage is claimed.

Independent v0.2 reload tracing did reproduce both pid=1 coroutine ticks and
manager pid=9 cid=-1 ticks. Current tracing showed only the designated worker,
then its replacement, scheduling after reload.
