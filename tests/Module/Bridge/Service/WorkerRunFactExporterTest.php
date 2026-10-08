<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Service\WorkerRunFactExporter;
use App\Tests\Module\Bridge\BridgeScenario;
use DoctrineMigrations\Version20261006005345;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../../../migrations/Version20261006005345.php';

final class WorkerRunFactExporterTest extends KernelTestCase
{
    use BridgeScenario;

    /** A fact whose run the retention sweep took is still the account's data. */
    public function test_it_exports_the_facts_of_the_account_alone_with_their_swept_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $exporting = $this->user($em, 'facts-export-mine@example.com');
        $project = $this->project($em, $exporting, 'Facts Export');
        $bridgeId = Uuid::v7();
        $kept = $this->seedRun($em, $project, receivedAt: new \DateTimeImmutable('2026-01-01 10:05:01'), workKind: 'review', bridgeId: $bridgeId, harness: 'codex', account: 'work');
        $this->seedHostSample($this->seedBridge($em, $exporting, $bridgeId), '2026-01-01 10:01:00', onAc: true);
        $this->seedUsage($em, $kept);
        $this->seedToolCall($kept);
        $kept->toolTimeMs = 4000;
        $kept->idleGapMs = 6000;
        $kept->peakContextTokens = 77_000;
        $em->flush();
        $swept = $this->seedRun($em, $project, cardNumber: 2);
        $this->seedRun($em, $this->project($em, $this->user($em, 'facts-export-other@example.com'), 'Other Facts'));
        $connection = $em->getConnection();
        $connection->executeStatement(Version20261006005345::BACKFILL_SQL);
        $connection->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $swept->id]);
        $em->clear();

        $rows = iterator_to_array($this->exporter()->export($exporting), false);

        usort($rows, static fn (array $a, array $b): int => $a['cardNumber'] <=> $b['cardNumber']);
        self::assertCount(2, $rows);
        self::assertSame([
            'runId' => (string) $kept->id,
            'project' => 'Facts Export',
            'subjectType' => 'card',
            'subjectId' => (string) $kept->subjectId,
            'cardNumber' => 1,
            'kind' => 'worker',
            'workKind' => 'review',
            'ruleId' => null,
            'experiment' => null,
            'variant' => null,
            'model' => 'claude-opus-5-5',
            'harness' => 'codex',
            'account' => 'work',
            'bridgeId' => (string) $bridgeId,
            'outcome' => 'succeeded',
            'startedAt' => '2026-01-01T10:00:00+00:00',
            'endedAt' => '2026-01-01T10:05:00+00:00',
            'receivedAt' => '2026-01-01T10:05:01+00:00',
            'durationMs' => 300000,
            'costMicroUsd' => 12345,
            'tokensIn' => 100,
            'tokensOut' => 20,
            'tokensCacheRead' => 300,
            'tokensCacheWrite' => 40,
            'usageSource' => 'reported',
            'toolTimeMs' => 4000,
            'modelTimeMs' => 290000,
            'toolCalls' => 1,
            'failedCalls' => 0,
            'longestCallMs' => 1500,
            'idleGapMs' => 6000,
            'subagentMs' => 0,
            'peakContextTokens' => 77_000,
            'meanCpuPct' => 20.0,
            'peakMemBytes' => 1000,
            'peakSwapBytes' => 0,
            'concurrentRuns' => 1,
            'onBattery' => false,
        ], $rows[0]);
        self::assertSame((string) $swept->id, $rows[1]['runId']);
        self::assertNull($rows[1]['costMicroUsd']);
    }

    public function test_the_archive_entry_is_named_after_the_facts(): void
    {
        self::bootKernel();

        self::assertSame('worker_run_facts.json', $this->exporter()->filename());
    }

    private function exporter(): WorkerRunFactExporter
    {
        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);

        return new WorkerRunFactExporter($facts);
    }
}
