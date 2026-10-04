<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ReopenLapsedWorkRequestsHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Scheduler\ReopenLapsedWorkRequestsTask;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReopenLapsedWorkRequestsTaskTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_sweep_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ReopenLapsedWorkRequestsTask::class] ?? null,
        );
    }

    public function test_the_task_reopens_the_lapsed_claims_and_logs_the_count(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-task@example.com'), 'Reopen Task');
        $lapsed = $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:29:00'));
        $logger = new RecordingLogger();

        $this->task($logger)();

        $em->clear();
        self::assertSame(WorkRequestState::Open, $em->find(WorkRequest::class, $lapsed->id)?->state);
        self::assertCount(1, $logger->records);
        self::assertSame('bridge.work_requests_reopened', $logger->records[0]['message']);
        self::assertSame(['reopened' => 1], $logger->records[0]['context']);
    }

    public function test_a_tick_that_reopens_nothing_logs_nothing(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'reopen-task-quiet@example.com'), 'Reopen Task Quiet');
        $this->seedWorkRequest($em, $project, state: WorkRequestState::Claimed, bridgeId: Uuid::v4(), claimToken: Uuid::v4(), leaseUntil: new \DateTimeImmutable('2026-10-01 12:31:00'));
        $logger = new RecordingLogger();

        $this->task($logger)();

        self::assertSame(1, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM work_requests WHERE state = 'claimed'"));
        self::assertSame([], $logger->records);
    }

    public function test_the_container_task_runs_end_to_end(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(ReopenLapsedWorkRequestsTask::class);
        self::assertInstanceOf(ReopenLapsedWorkRequestsTask::class, $task);

        $task();
    }

    private function task(RecordingLogger $logger): ReopenLapsedWorkRequestsTask
    {
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $outbox = self::getContainer()->get(OutboxWriter::class);
        self::assertInstanceOf(OutboxWriter::class, $outbox);

        $announcer = self::getContainer()->get(WorkRequestAnnouncer::class);
        self::assertInstanceOf(WorkRequestAnnouncer::class, $announcer);

        return new ReopenLapsedWorkRequestsTask(
            new ReopenLapsedWorkRequestsHandler(new WorkRequestRepository($registry), $outbox, $this->em(), new MockClock('2026-10-01 12:30:00'), $announcer),
            $logger,
        );
    }
}
