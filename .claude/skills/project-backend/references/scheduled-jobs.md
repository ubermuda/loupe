# Scheduled jobs

Read this before you add a recurring job.

A recurring job is an invokable task class under `src/Module/*/Scheduler/`. It carries `#[AsCronTask('<cron expression>')]` and delegates to a command handler. It needs no message class, no `#[AsMessageHandler]`, and no `messenger.yaml` routing.

```php
#[AsCronTask('*/5 * * * *')]
final readonly class DrainSiteReviewOutboxTask
{
    public function __construct(private DrainOutboxHandler $drainOutbox) {}

    public function __invoke(): void { ($this->drainOutbox)(new DrainOutboxCommand()); }
}
```

## Never add a schedule provider

Do not add a `ScheduleProviderInterface` or `#[AsSchedule]` class. Only one provider may claim a schedule name, so the idiom does not compose across modules. The second module must either edit the first module's provider, which breaks the module boundary, or claim a new schedule name. A new schedule name mints a new `scheduler_<name>` transport, and you must then add that transport to every `messenger:consume` invocation, including `compose.yaml`, the `worker_command` in `terraform/main.tf`, and the production deploy config. Tagged tasks all land on the one `default` schedule and the single `scheduler_default` transport that the worker already consumes. `AddScheduleMessengerPass` decorates a provider when one exists and synthesises the schedule when none does, so removal of the last provider changes nothing about the transports.

## Use a cron expression

Use a cron expression, not `#[AsPeriodicTask]`. The periodic trigger counts down from worker boot, and `--time-limit=3600` recycles the worker every hour, so a periodic tick can starve. The cron grid is wall-clock and survives a restart.

## Pair every task with a command and a test

The wiring lives in an attribute and a compiler pass, so nothing else notices a tick that goes missing. Give every task a manual `app:*` console command, which is the backstop and the dev/e2e seam, and a registration test:

```php
self::assertSame(
    '*/5 * * * *',
    ScheduledTasks::cronExpressions(self::getContainer())[DrainSiteReviewOutboxTask::class] ?? null,
);
```

`App\Tests\Support\ScheduledTasks` reads the built schedule back. See `tests/Module/SiteReview/Scheduler/DrainSiteReviewOutboxTaskTest.php`.
