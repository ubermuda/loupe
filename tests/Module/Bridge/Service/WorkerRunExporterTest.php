<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunExporter;
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
        $run = new WorkerRun(
            project: $project,
            bridgeId: $bridgeId,
            sessionId: $sessionId,
            cardId: $cardId,
            cardNumber: 7,
            ruleName: 'plan',
            startedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            endedAt: new \DateTimeImmutable('2026-09-13T10:00:21+00:00'),
            exitCode: 0,
            hasResult: true,
            failureReason: null,
            output: 'all good',
            receivedAt: new \DateTimeImmutable('2026-09-13T10:00:22+00:00'),
        );

        $rows = iterator_to_array(new WorkerRunExporter($this->repositoryReturning($run))->export($owner));

        self::assertCount(1, $rows);
        self::assertSame([
            'project' => 'My project',
            'bridgeId' => (string) $bridgeId,
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
        ], $rows[0]);
    }

    /** A spawn failure is the row a reader most wants, so the reason has to survive the export. */
    public function test_a_spawn_failure_exports_its_reason_and_no_exit_code(): void
    {
        $owner = new User('Alice A', 'alice@example.com', 'x');
        $run = new WorkerRun(
            project: new Project($owner, 'My project'),
            bridgeId: Uuid::v7(),
            sessionId: Uuid::v4(),
            cardId: Uuid::v7(),
            cardNumber: 7,
            ruleName: 'plan',
            startedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            endedAt: new \DateTimeImmutable('2026-09-13T10:00:00+00:00'),
            exitCode: null,
            failureReason: 'binary not found',
            output: '',
        );

        $rows = iterator_to_array(new WorkerRunExporter($this->repositoryReturning($run))->export($owner));

        self::assertNull($rows[0]['exitCode']);
        self::assertNull($rows[0]['hasResult']);
        self::assertSame('binary not found', $rows[0]['failureReason']);
    }

    public function test_the_archive_entry_is_named_after_the_runs(): void
    {
        self::assertSame('worker_runs.json', new WorkerRunExporter($this->repositoryReturning())->filename());
    }

    private function repositoryReturning(WorkerRun ...$runs): WorkerRunRepository
    {
        /** @var WorkerRunRepository&Stub $repository */
        $repository = $this->createStub(WorkerRunRepository::class);
        $repository->method('findByOwner')->willReturn($runs);

        return $repository;
    }
}
