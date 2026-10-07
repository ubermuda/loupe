<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Scheduler;

use App\Module\Bridge\Command\ExpireSubjectWorkRequestsHandler;
use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\Scheduler\ExpireSubjectWorkRequestsTask;
use App\Module\Bridge\Service\WorkRequestAnnouncer;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Bridge\WorkSubject\WorkSubjectHandlers;
use App\Outbox\OutboxWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\WorkSubject\RecordingWorkSubjectHandler;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;

final class ExpireSubjectWorkRequestsTaskTest extends KernelTestCase
{
    use BridgeScenario;

    private const string NOW = '2026-10-01 12:00:00';

    public function test_the_sweep_is_registered_on_the_default_schedule_every_minute(): void
    {
        self::bootKernel();

        self::assertSame(
            '* * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[ExpireSubjectWorkRequestsTask::class] ?? null,
        );
    }

    public function test_the_task_expires_the_requests_and_logs_the_count(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'expire-task@example.com'), 'Expire Task');
        $this->seedWorkRequest($em, $project, createdAt: new \DateTimeImmutable('2026-10-01 09:00:00'), subject: new WorkSubject(RecordingWorkSubjectHandler::TYPE, Uuid::v7()));
        $logger = new RecordingLogger();

        $this->task($logger)();

        self::assertCount(1, $logger->records);
        self::assertSame('bridge.subject_work_requests_expired', $logger->records[0]['message']);
        self::assertSame(['expired' => 1], $logger->records[0]['context']);
    }

    public function test_a_tick_that_expires_nothing_logs_nothing(): void
    {
        self::bootKernel();
        $logger = new RecordingLogger();

        $this->task($logger)();

        self::assertSame([], $logger->records);
    }

    public function test_the_container_task_runs_end_to_end(): void
    {
        self::bootKernel();
        $task = self::getContainer()->get(ExpireSubjectWorkRequestsTask::class);
        self::assertInstanceOf(ExpireSubjectWorkRequestsTask::class, $task);

        $task();
    }

    private function task(RecordingLogger $logger): ExpireSubjectWorkRequestsTask
    {
        $clock = new MockClock(self::NOW);
        $withdraw = new WithdrawWorkRequestHandler(
            $this->service(WorkRequestRepository::class),
            $this->service(OutboxWriter::class),
            $this->em(),
            $clock,
            $this->service(Auditor::class),
            $this->service(WorkRequestAnnouncer::class),
            new WorkSubjectHandlers([new RecordingWorkSubjectHandler()]),
        );

        return new ExpireSubjectWorkRequestsTask(
            new ExpireSubjectWorkRequestsHandler($this->service(WorkRequestRepository::class), $withdraw, $clock, 120),
            $logger,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
