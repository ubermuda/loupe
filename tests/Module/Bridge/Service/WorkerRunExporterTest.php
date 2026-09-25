<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\Service\WorkerRunExporter;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Entity\Project;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WorkerRunExporterTest extends TestCase
{
    public function test_it_exports_every_field_a_reader_needs(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project');
        $bridgeId = Uuid::v7();
        $cardId = Uuid::v7();
        $sessionId = Uuid::v4();
        $runKey = Uuid::v7();
        $run = new WorkerRun(
            project: $project,
            bridgeId: $bridgeId,
            cardId: $cardId,
            cardNumber: 7,
            ruleName: 'plan',
            state: WorkerRunState::Succeeded,
            runKey: $runKey,
            sessionId: $sessionId,
            startedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            endedAt: new \DateTimeImmutable('2026-09-13T10:00:21+00:00'),
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            output: 'all good',
            receivedAt: new \DateTimeImmutable('2026-09-13T10:00:22+00:00'),
        );
        $history = [
            new WorkerRunStateChange($run, WorkerRunState::Running, new \DateTimeImmutable('2026-09-13T10:00:00+00:00'), new \DateTimeImmutable('2026-09-13T10:00:01+00:00')),
            new WorkerRunStateChange($run, WorkerRunState::Succeeded, new \DateTimeImmutable('2026-09-13T10:00:21+00:00'), new \DateTimeImmutable('2026-09-13T10:00:22+00:00')),
        ];

        $rows = iterator_to_array($this->exporter([$run], $history)->export($owner));

        self::assertCount(1, $rows);
        self::assertSame([
            'project' => 'My project',
            'kind' => 'worker',
            'bridgeId' => (string) $bridgeId,
            'runKey' => (string) $runKey,
            'state' => 'succeeded',
            'sessionId' => (string) $sessionId,
            'cardId' => (string) $cardId,
            'cardNumber' => 7,
            'ruleName' => 'plan',
            'startedAt' => '2026-09-13T10:00:00+00:00',
            'endedAt' => '2026-09-13T10:00:21+00:00',
            'exitCode' => 0,
            'hasResult' => true,
            'failureReason' => null,
            'output' => 'all good',
            'receivedAt' => '2026-09-13T10:00:22+00:00',
            'resultStatus' => null,
            'resultFields' => null,
            'continues' => null,
            'resumeIndex' => null,
            'resumeCap' => null,
            'cardColumn' => null,
            'resumeSkipped' => null,
            'usageSource' => null,
            'history' => [
                ['state' => 'running', 'at' => '2026-09-13T10:00:00+00:00', 'receivedAt' => '2026-09-13T10:00:01+00:00'],
                ['state' => 'succeeded', 'at' => '2026-09-13T10:00:21+00:00', 'receivedAt' => '2026-09-13T10:00:22+00:00'],
            ],
        ], $rows[0]);
    }

    public function test_each_run_carries_only_its_own_history(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project');
        $first = $this->queuedRun($project);
        $second = $this->queuedRun($project);
        $history = [new WorkerRunStateChange($second, WorkerRunState::Queued, new \DateTimeImmutable('2026-09-13T10:00:00+00:00'))];

        $rows = iterator_to_array($this->exporter([$first, $second], $history)->export($owner));

        self::assertSame([], $rows[0]['history']);
        self::assertCount(1, $rows[1]['history']);
    }

    /** A queued run has no session, no start and no end yet, so each exports as null. */
    public function test_a_queued_run_exports_null_for_what_it_does_not_have_yet(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');

        $rows = iterator_to_array($this->exporter([$this->queuedRun(new Project($owner, 'My project'))], [])->export($owner));

        self::assertSame('queued', $rows[0]['state']);
        self::assertNull($rows[0]['sessionId']);
        self::assertNull($rows[0]['startedAt']);
        self::assertNull($rows[0]['endedAt']);
    }

    /** A spawn failure is the row a reader most wants, so the reason has to survive the export. */
    public function test_a_spawn_failure_exports_its_reason_and_no_exit_code(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $run = new WorkerRun(
            project: new Project($owner, 'My project'),
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'plan',
            state: WorkerRunState::NotStarted,
            sessionId: Uuid::v4(),
            startedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            endedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            exitCode: null,
            failureReason: 'binary not found',
            output: '',
        );

        $rows = iterator_to_array($this->exporter([$run], [])->export($owner));

        self::assertNull($rows[0]['exitCode']);
        self::assertNull($rows[0]['hasResult']);
        self::assertSame('binary not found', $rows[0]['failureReason']);
    }

    public function test_a_resume_exports_its_link_and_its_structured_result(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $project = new Project($owner, 'My project');
        $first = $this->queuedRun($project);
        $resume = new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'plan',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
            continuesRun: $first,
            resumeIndex: 2,
            resumeCap: 2,
            cardColumn: 'implementation',
        );
        $resume->recordOutcome(WorkerRunState::GaveUp, new \DateTimeImmutable('2026-09-13T10:00:21+00:00'), 0, true, null, 'CI still runs', 'unfinished', ['pullRequest' => 'https://example.com/pull/1'], 'card_moved');

        $rows = iterator_to_array($this->exporter([$resume], [])->export($owner));

        self::assertSame('gave-up', $rows[0]['state']);
        self::assertSame('unfinished', $rows[0]['resultStatus']);
        self::assertSame(['pullRequest' => 'https://example.com/pull/1'], $rows[0]['resultFields']);
        self::assertSame($first->runKey?->toRfc4122(), $rows[0]['continues']);
        self::assertSame(2, $rows[0]['resumeIndex']);
        self::assertSame(2, $rows[0]['resumeCap']);
        self::assertSame('implementation', $rows[0]['cardColumn']);
        self::assertSame('card_moved', $rows[0]['resumeSkipped']);
    }

    public function test_an_interactive_run_exports_its_kind_and_no_bridge(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $run = new WorkerRun(
            project: new Project($owner, 'My project'),
            bridgeId: null,
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'Pairing on the tech design',
            state: WorkerRunState::Closed,
            sessionId: Uuid::v4(),
            kind: WorkerRunKind::Interactive,
        );

        $rows = iterator_to_array($this->exporter([$run], [])->export($owner));

        self::assertSame('interactive', $rows[0]['kind']);
        self::assertNull($rows[0]['bridgeId']);
        self::assertSame('closed', $rows[0]['state']);
    }

    public function test_the_archive_entry_is_named_after_the_runs(): void
    {
        self::assertSame('worker_runs.json', $this->exporter([], [])->filename());
    }

    private function queuedRun(Project $project): WorkerRun
    {
        return new WorkerRun(
            project: $project,
            bridgeId: Uuid::v7(),
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'plan',
            state: WorkerRunState::Queued,
            runKey: Uuid::v7(),
        );
    }

    /**
     * @param list<WorkerRun>            $runs
     * @param list<WorkerRunStateChange> $history
     */
    private function exporter(array $runs, array $history): WorkerRunExporter
    {
        /** @var WorkerRunRepository&Stub $workerRuns */
        $workerRuns = $this->createStub(WorkerRunRepository::class);
        $workerRuns->method('findByOwner')->willReturn($runs);

        /** @var WorkerRunStateChangeRepository&Stub $stateChanges */
        $stateChanges = $this->createStub(WorkerRunStateChangeRepository::class);
        $stateChanges->method('findByOwner')->willReturn($history);

        return new WorkerRunExporter($workerRuns, $stateChanges);
    }
}
