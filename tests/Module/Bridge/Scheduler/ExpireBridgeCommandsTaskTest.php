<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ExpireBridgeCommandsHandler;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Scheduler\ExpireBridgeCommandsTask;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ExpireBridgeCommandsTaskTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_sweep_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ExpireBridgeCommandsTask::class] ?? null,
        );
    }

    public function test_the_task_expires_the_due_commands_and_logs_the_count(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'command-sweep@example.com'), 'Command Sweep');
        $due = $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: Uuid::v4()), expiresAt: new \DateTimeImmutable('2026-09-29 11:59:00'));
        $logger = new RecordingLogger();

        $this->task($logger)();

        $em->clear();
        self::assertSame(BridgeCommandState::Expired, $em->find(BridgeCommand::class, $due->id)?->state);
        self::assertCount(1, $logger->records);
        self::assertSame('bridge.commands_expired', $logger->records[0]['message']);
        self::assertSame(['expired' => 1], $logger->records[0]['context']);
    }

    public function test_a_tick_that_expires_nothing_logs_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'command-sweep-quiet@example.com'), 'Quiet Sweep');
        $this->seedCommand($em, $this->seedRun($em, $project, bridgeId: Uuid::v4()), expiresAt: new \DateTimeImmutable('2026-09-29 12:15:00'));
        $logger = new RecordingLogger();

        $this->task($logger)();

        self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM bridge_commands WHERE state = 'pending'"));
        self::assertSame([], $logger->records);
    }

    public function test_the_container_task_runs_end_to_end(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(ExpireBridgeCommandsTask::class);
        self::assertInstanceOf(ExpireBridgeCommandsTask::class, $task);

        $task();
    }

    private function task(RecordingLogger $logger): ExpireBridgeCommandsTask
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $runsChanged = self::getContainer()->get(WorkerRunChangedPublisher::class);
        self::assertInstanceOf(WorkerRunChangedPublisher::class, $runsChanged);

        return new ExpireBridgeCommandsTask(
            new ExpireBridgeCommandsHandler(new BridgeCommandRepository($registry), $runsChanged, new MockClock('2026-09-29 12:00:00')),
            $logger,
        );
    }
}
