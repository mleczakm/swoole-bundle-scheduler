# Examples

Copy-paste starting points. Namespaced `App\Scheduler\` so they drop into a typical Symfony app.

| File | What it shows |
|---|---|
| [`Schedule/MainSchedule.php`](Schedule/MainSchedule.php) | An `#[AsSchedule]` provider - the only thing most apps need to write |
| [`Message/SampleTask.php`](Message/SampleTask.php) + [`SampleTaskHandler.php`](Message/SampleTaskHandler.php) | A scheduled message and its handler |
| [`config/swoole_bundle_scheduler.yaml`](config/swoole_bundle_scheduler.yaml) | Every config knob, annotated |
| [`Scheduler/ConnectionCheckingScheduler.php`](Scheduler/ConnectionCheckingScheduler.php) | Replacing the `Scheduler` service to run logic before every poll |

See [`../docs/usage.md`](../docs/usage.md) for the walkthrough and
[`../docs/production-hardening.md`](../docs/production-hardening.md) for when to turn on the
lock / watchdog / heartbeat / health-check.
