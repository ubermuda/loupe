<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Scheduler;

use App\Module\OAuth\Scheduler\PurgeExpiredOAuthTokensTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PurgeExpiredOAuthTokensTaskTest extends KernelTestCase
{
    public function test_it_runs_every_hour_on_the_default_schedule(): void
    {
        self::bootKernel();

        self::assertSame(
            '17 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[PurgeExpiredOAuthTokensTask::class] ?? null,
        );
    }
}
