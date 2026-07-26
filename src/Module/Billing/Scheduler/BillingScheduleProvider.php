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
 * that drains `async`. The sweep is marker-idempotent, so no lock/stateful
 * cache is configured: a duplicate tick re-selects nothing, and a tick missed
 * during a worker restart is caught by the next one.
 */
#[AsSchedule('default')]
final readonly class BillingScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return new Schedule()->add(
            RecurringMessage::every('1 hour', new SweepEndedTrialsMessage()),
        );
    }
}
