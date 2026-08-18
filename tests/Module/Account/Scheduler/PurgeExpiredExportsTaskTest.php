<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Scheduler;

use App\Module\Account\Scheduler\PurgeExpiredExportsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tick's wiring is entirely in an attribute and a compiler pass, so nothing
 * in the purger's own tests would notice it going missing.
 */
final class PurgeExpiredExportsTaskTest extends KernelTestCase
{
    public function test_the_purge_is_registered_on_the_default_schedule_half_past_every_hour(): void
    {
        self::bootKernel();

        self::assertSame(
            '30 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PurgeExpiredExportsTask::class] ?? null,
        );
    }

    public function test_the_task_purges_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(PurgeExpiredExportsTask::class);
        self::assertInstanceOf(PurgeExpiredExportsTask::class, $task);

        // Nothing is expired in a fresh schema, so a clean return is the whole
        // assertion: the task resolves its purger and runs end to end.
        $task();
    }
}
