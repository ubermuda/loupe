<?php

declare(strict_types=1);

namespace App\Tests\Module\Billing\Scheduler;

use App\Module\Billing\Scheduler\SweepEndedTrialsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Expired trials are only disabled if the hourly tick fires, and the wiring is
 * entirely in an attribute and a compiler pass — nothing in the sweep's own
 * tests would notice it going missing.
 */
final class SweepEndedTrialsTaskTest extends KernelTestCase
{
    public function test_the_sweep_is_registered_on_the_default_schedule_hourly(): void
    {
        self::bootKernel();

        self::assertSame(
            '0 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SweepEndedTrialsTask::class] ?? null,
        );
    }

    public function test_the_task_sweeps_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(SweepEndedTrialsTask::class);
        self::assertInstanceOf(SweepEndedTrialsTask::class, $task);

        // With no expired trials seeded the sweep selects nothing, so a clean
        // return is the whole assertion: the task resolves its handler and runs
        // end to end.
        $task();
    }
}
