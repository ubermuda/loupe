<?php

declare(strict_types=1);

namespace App\Tests\Module\SiteReview\Scheduler;

use App\Module\SiteReview\Scheduler\DrainSiteReviewOutboxTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The tick's wiring is entirely in an attribute and a compiler pass, so nothing
 * in the drain handler's own tests would notice it going missing.
 */
final class DrainSiteReviewOutboxTaskTest extends KernelTestCase
{
    public function test_the_drain_is_registered_on_the_default_schedule_every_five_minutes(): void
    {
        self::bootKernel();

        self::assertSame(
            '*/5 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[DrainSiteReviewOutboxTask::class] ?? null,
        );
    }

    public function test_the_task_drains_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(DrainSiteReviewOutboxTask::class);
        self::assertInstanceOf(DrainSiteReviewOutboxTask::class, $task);

        // Nothing is owed on an empty outbox, so a clean return is the whole
        // assertion: the task resolves its handler and runs end to end.
        $task();
    }
}
