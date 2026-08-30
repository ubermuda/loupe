<?php

declare(strict_types=1);

namespace App\Tests\Audit\Scheduler;

use App\Audit\Scheduler\PurgeAuditLogTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tick's wiring is entirely in an attribute and a compiler pass, so nothing
 * in the purger's own tests would notice it going missing.
 */
final class PurgeAuditLogTaskTest extends KernelTestCase
{
    public function test_the_purge_is_registered_on_the_default_schedule_every_hour(): void
    {
        self::bootKernel();

        self::assertSame(
            '45 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PurgeAuditLogTask::class] ?? null,
        );
    }

    public function test_the_task_purges_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(PurgeAuditLogTask::class);
        self::assertInstanceOf(PurgeAuditLogTask::class, $task);

        // An empty window owes nothing, so a clean return is the whole
        // assertion: the task resolves its purger and runs end to end.
        $task();
    }
}
