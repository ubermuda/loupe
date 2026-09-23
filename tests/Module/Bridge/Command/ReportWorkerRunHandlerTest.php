<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Bridge\Command\ReportWorkerRunCommand;
use App\Module\Bridge\Command\ReportWorkerRunHandler;
use App\Module\Bridge\Entity\WorkerRun;
use App\Tests\Module\Bridge\BridgeScenario;
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
            ruleName: 'plan',
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

        $context = $audit->record('bridge.worker_run_recorded')->context;
        self::assertArrayHasKey('hasResult', $context);
        self::assertFalse($context['hasResult']);
    }

    private function handler(): ReportWorkerRunHandler
    {
        $handler = self::getContainer()->get(ReportWorkerRunHandler::class);
        self::assertInstanceOf(ReportWorkerRunHandler::class, $handler);

        return $handler;
    }
}
