<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Scheduler;

use App\Module\Forge\Scheduler\SweepForgePullRequestsTask;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SweepForgePullRequestsTaskTest extends KernelTestCase
{
    public function test_the_sweep_is_registered_on_the_default_schedule_every_ten_minutes(): void
    {
        self::bootKernel();

        self::assertSame(
            '*/10 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[SweepForgePullRequestsTask::class] ?? null,
        );
    }

    public function test_the_task_sweeps_when_invoked(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(SweepForgePullRequestsTask::class);
        self::assertInstanceOf(SweepForgePullRequestsTask::class, $task);

        // The table is empty, so a clean return proves the task resolves its handler and runs.
        $task();
    }

    public function test_the_console_command_sweeps(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:sweep-forge-pull-requests'));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Queued 0 pull request refresh(es).', $tester->getDisplay());
    }
}
