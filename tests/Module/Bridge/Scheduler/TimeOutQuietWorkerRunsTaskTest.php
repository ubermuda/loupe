<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Scheduler;

use App\Module\Bridge\Scheduler\TimeOutQuietWorkerRunsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TimeOutQuietWorkerRunsTaskTest extends KernelTestCase
{
    public function test_the_sweep_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[TimeOutQuietWorkerRunsTask::class] ?? null,
        );
    }

    public function test_the_task_sweeps_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(TimeOutQuietWorkerRunsTask::class);
        self::assertInstanceOf(TimeOutQuietWorkerRunsTask::class, $task);

        // Nothing is open on an empty table, so a clean return is the whole
        // assertion: the task resolves its handler and runs end to end.
        $task();
    }
}
