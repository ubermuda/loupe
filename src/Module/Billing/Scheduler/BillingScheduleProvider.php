<?php

declare(strict_types=1);

namespace App\Module\Billing\Scheduler;

use App\Module\Billing\Messenger\SweepEndedTrialsMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Consumed as the `scheduler_default` transport by the same worker process
 * that drains `async`. A cron trigger, not `every('1 hour')`: the stateless
 * periodic trigger counts down from worker boot, so a worker recycled by
 * --time-limit=3600 restarts the countdown and the tick may never fire. The
 * cron grid is wall-clock — restarts don't move it, and an hour missed while
 * no worker ran is genuinely caught at the next top of hour. The sweep is
 * marker-idempotent, so no lock or stateful cache is configured: a duplicate
 * tick re-selects nothing.
 *
 * Re-applying the symfony/scheduler recipe regenerates `src/Schedule.php`
 * with its own `#[AsSchedule('default')]`, which silently shadows this
 * provider (last registration wins) — delete that file again if it reappears.
 */
#[AsSchedule('default')]
final readonly class BillingScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(
            RecurringMessage::cron('0 * * * *', new SweepEndedTrialsMessage()),
        );
    }
}
