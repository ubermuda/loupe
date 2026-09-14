<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Scheduler;

use App\Module\Bridge\Scheduler\PurgeWorkerRunsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tick's wiring is entirely in an attribute and a compiler pass, so nothing
 * in the sweep's own tests would notice it going missing.
 */
final class PurgeWorkerRunsTaskTest extends KernelTestCase
{
    public function test_the_sweep_is_registered_on_the_default_schedule_every_hour(): void
    {
        self::bootKernel();

        self::assertSame(
            '20 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PurgeWorkerRunsTask::class] ?? null,
        );
    }

    public function test_the_task_sweeps_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(PurgeWorkerRunsTask::class);
        self::assertInstanceOf(PurgeWorkerRunsTask::class, $task);

        // Nothing is owed on an empty table, so a clean return is the whole
        // assertion: the task resolves its handler and runs end to end.
        $task();
    }
}
