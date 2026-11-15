# Examples

Copy the message, handler and schedule into your application's App namespace and
register the bundle. The configuration example enables the optional health integration;
install and configure macpaw/symfony-health-check-bundle first, or disable health_check.

ConnectionCheckingScheduler demonstrates a custom Scheduler implementation. It returns
the inner scheduler's next due date. Prefer pre_run for a simple connection check.

See ../docs/usage.md for complete 0.3 configuration and migration details.
