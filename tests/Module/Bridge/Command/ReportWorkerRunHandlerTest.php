<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\ReportWorkerRunCommand;
use App\Module\Bridge\Command\ReportWorkerRunHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ReportWorkerRunHandlerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_stores_and_audits_whether_the_run_had_a_result(): void
    {
        self::bootKernel();
        $audit = RecordingAuditor::installedIn(self::getContainer());
        $em = $this->em();
        $owner = $this->user($em, 'report-run-result@example.com');
        $project = $this->project($em, $owner, 'Result Handler');

        $result = $this->handler()(new ReportWorkerRunCommand(
            owner: $owner,
            handle: (string) $project->id,
            bridgeId: Uuid::v7(),
            sessionId: Uuid::v4(),
            cardId: Uuid::v7(),
            cardNumber: 9,
            startedAt: new \DateTimeImmutable('2026-09-23 10:00:00'),
            endedAt: new \DateTimeImmutable('2026-09-23 10:01:00'),
            exitCode: 0,
            hasResult: false,
            failureReason: null,
            output: '',
        ));

        self::assertNotNull($result->run);
        $id = $result->run->id;
        $em->clear();
        $stored = $em->find(WorkerRun::class, $id);
        self::assertInstanceOf(WorkerRun::class, $stored);
        self::assertFalse($stored->hasResult);
        self::assertSame(WorkerRunState::NoResult, $stored->state);

        $context = $audit->record('bridge.worker_run_recorded')->context;
        self::assertArrayHasKey('hasResult', $context);
        self::assertFalse($context['hasResult']);
    }

    public function test_a_new_run_announces_its_card_after_the_commit_and_a_repeat_does_not(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'report-run-announce@example.com');
        $project = $this->project($em, $owner, 'Announce Handler');
        $cardId = Uuid::v7();
        $startedAt = new \DateTimeImmutable('2026-09-23 10:00:00');
        $changes = DispatchedEvents::of(self::getContainer(), WorkerRunChanged::class);
        $depth = $em->getConnection()->getTransactionNestingLevel();

        $first = $this->handler()($this->command($owner, $project, $cardId, $startedAt));
        $repeat = $this->handler()($this->command($owner, $project, $cardId, $startedAt));

        self::assertTrue($first->created);
        self::assertFalse($repeat->created);
        self::assertCount(1, $changes->events());
        self::assertEquals($project->id, $changes->events()[0]->projectId);
        self::assertSame([$cardId->toRfc4122()], $changes->events()[0]->cardIds);
        self::assertNotNull($first->run);
        self::assertSame([(string) $first->run->id], $changes->events()[0]->runIds);
        self::assertSame([$depth], $changes->transactionDepths());
    }

    private function command(User $owner, Project $project, Uuid $cardId, \DateTimeImmutable $startedAt): ReportWorkerRunCommand
    {
        return new ReportWorkerRunCommand(
            owner: $owner,
            handle: (string) $project->id,
            bridgeId: Uuid::fromString('0199a0e2-9d4c-7c5e-9f2a-3b1c6d7e8f90'),
            sessionId: Uuid::v4(),
            cardId: $cardId,
            cardNumber: 9,
            startedAt: $startedAt,
            endedAt: new \DateTimeImmutable('2026-09-23 10:01:00'),
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            output: '',
        );
    }

    private function handler(): ReportWorkerRunHandler
    {
        $handler = self::getContainer()->get(ReportWorkerRunHandler::class);
        self::assertInstanceOf(ReportWorkerRunHandler::class, $handler);

        return $handler;
    }
}
