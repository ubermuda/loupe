<?php

declare(strict_types=1);

namespace App\Tests\Module\Board\Scheduler;

use App\Module\Board\Scheduler\AnnounceStuckPullRequestsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AnnounceStuckPullRequestsTaskTest extends KernelTestCase
{
    public function test_the_announcement_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[AnnounceStuckPullRequestsTask::class] ?? null,
        );
    }
}
