<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunUsage;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkerRunUsageSource;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261006005345;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261006005345.php';

final class WorkerRunFactsBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    private EntityManagerInterface $em;
    private Connection $connection;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->em();
        $this->connection = $this->em->getConnection();
        $this->project = $this->project($this->em, $this->user($this->em, 'facts-backfill@example.com'), 'Facts Backfill');
    }

    public function test_it_copies_the_run_and_sums_its_usage(): void
    {
        $bridgeId = Uuid::v7();
        $run = $this->seedFactRun(bridgeId: $bridgeId, ruleId: 'implement-on-entry');
        $run->experiment = 'impl-model';
        $run->variant = 'opus';
        $this->usage($run, 'claude-haiku', '0.012345', outputTokens: 900);
        $this->usage($run, 'claude-opus', '0.5', outputTokens: 10);

        $this->backfillTwice();

        self::assertSame([
            'subject_type' => 'card',
            'subject_id' => (string) $run->subjectId,
            'card_number' => 7,
            'kind' => 'worker',
            'work_kind' => 'implement',
            'rule_id' => 'implement-on-entry',
            'experiment' => 'impl-model',
            'variant' => 'opus',
            'model' => 'claude-opus',
            'bridge_id' => (string) $bridgeId,
            'outcome' => 'succeeded',
            'started_at' => '2026-01-01 10:00:00',
            'ended_at' => '2026-01-01 10:05:00',
            'received_at' => '2026-01-01 10:05:01',
            'duration_ms' => 300000,
            'cost_micro_usd' => 512345,
            'tokens_in' => 200,
            'tokens_out' => 910,
            'tokens_cache_read' => 600,
            'tokens_cache_write' => 80,
            'usage_source' => 'reported',
            'project_id' => (string) $this->project->id,
        ], $this->fact($run));
    }

    public function test_one_unpriced_usage_row_makes_the_cost_unknown_and_never_names_the_unpriced_model(): void
    {
        $run = $this->seedFactRun();
        $this->usage($run, 'unpriced-model', null, outputTokens: 5000, source: WorkerRunUsageSource::Estimated);
        $this->usage($run, 'claude-sonnet', '0.000001', source: WorkerRunUsageSource::Estimated);

        $this->backfillTwice();

        $fact = $this->fact($run);
        self::assertNull($fact['cost_micro_usd']);
        self::assertSame('claude-sonnet', $fact['model']);
        self::assertSame(5020, $fact['tokens_out']);
        self::assertSame('estimated', $fact['usage_source']);
    }

    public function test_a_cost_tie_names_the_model_with_more_output_then_the_first_name(): void
    {
        $byOutput = $this->seedFactRun();
        $this->usage($byOutput, 'a-model', '0.1', outputTokens: 10);
        $this->usage($byOutput, 'b-model', '0.1', outputTokens: 50);
        $byName = $this->seedFactRun();
        $this->usage($byName, 'd-model', '0.1', outputTokens: 10);
        $this->usage($byName, 'c-model', '0.1', outputTokens: 10);

        $this->backfillTwice();

        self::assertSame('b-model', $this->fact($byOutput)['model']);
        self::assertSame('c-model', $this->fact($byName)['model']);
    }

    public function test_a_usage_source_with_no_rows_counts_zero(): void
    {
        $run = $this->seedFactRun();
        $run->usageSource = WorkerRunUsageSource::Reported;
        $this->em->flush();

        $this->backfillTwice();

        $fact = $this->fact($run);
        self::assertSame(
            [0, 0, 0, 0, 0, null],
            [$fact['cost_micro_usd'], $fact['tokens_in'], $fact['tokens_out'], $fact['tokens_cache_read'], $fact['tokens_cache_write'], $fact['model']],
        );
    }

    public function test_a_run_without_usage_has_unknown_usage(): void
    {
        $run = $this->seedFactRun();

        $this->backfillTwice();

        $fact = $this->fact($run);
        self::assertSame(
            [null, null, null, null, null, null, null],
            [$fact['cost_micro_usd'], $fact['tokens_in'], $fact['tokens_out'], $fact['tokens_cache_read'], $fact['tokens_cache_write'], $fact['model'], $fact['usage_source']],
        );
        self::assertSame(300000, $fact['duration_ms']);
    }

    public function test_an_unstarted_run_has_no_duration(): void
    {
        $run = $this->seedFactRun(state: WorkerRunState::Queued, startedAt: null, endedAt: null);

        $this->backfillTwice();

        $fact = $this->fact($run);
        self::assertSame('queued', $fact['outcome']);
        self::assertNull($fact['started_at']);
        self::assertNull($fact['ended_at']);
        self::assertNull($fact['duration_ms']);
    }

    public function test_a_second_backfill_brings_a_stale_row_up_to_date(): void
    {
        $run = $this->seedFactRun();
        $this->backfillTwice();
        $run->moveTo(WorkerRunState::TimedOut);
        $this->em->flush();

        $this->connection->executeStatement(Version20261006005345::BACKFILL_SQL);

        self::assertSame('timed-out', $this->fact($run)['outcome']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));
    }

    public function test_the_backfill_writes_the_same_rows_as_the_writer(): void
    {
        $priced = $this->seedFactRun(ruleId: 'implement-on-entry');
        $priced->experiment = 'impl-model';
        $priced->variant = 'opus';
        $this->usage($priced, 'claude-haiku', '0.012345', outputTokens: 900);
        $this->usage($priced, 'claude-opus', '0.5', outputTokens: 10);
        $unpriced = $this->seedFactRun();
        $this->usage($unpriced, 'unpriced-model', null, source: WorkerRunUsageSource::Estimated);
        $this->usage($unpriced, 'claude-sonnet', '0.000001', source: WorkerRunUsageSource::Estimated);
        $this->seedFactRun();
        $sourceWithoutRows = $this->seedFactRun();
        $sourceWithoutRows->usageSource = WorkerRunUsageSource::Reported;
        $this->seedFactRun(state: WorkerRunState::Queued, startedAt: null, endedAt: null);
        $this->em->flush();
        $runIds = array_map(
            Uuid::fromString(...),
            $this->connection->fetchFirstColumn('SELECT id FROM bridge_worker_runs'),
        );
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);

        $this->connection->executeStatement('DELETE FROM bridge_worker_run_facts');
        $writer->upsert($runIds);
        $written = $this->connection->fetchAllAssociative('SELECT * FROM bridge_worker_run_facts ORDER BY run_id');
        $this->connection->executeStatement('DELETE FROM bridge_worker_run_facts');
        $this->connection->executeStatement(Version20261006005345::BACKFILL_SQL);

        self::assertCount(5, $written);
        self::assertSame($written, $this->connection->fetchAllAssociative('SELECT * FROM bridge_worker_run_facts ORDER BY run_id'));
    }

    private function seedFactRun(
        ?Uuid $bridgeId = null,
        ?string $ruleId = null,
        WorkerRunState $state = WorkerRunState::Succeeded,
        ?\DateTimeImmutable $startedAt = new \DateTimeImmutable('2026-01-01 10:00:00'),
        ?\DateTimeImmutable $endedAt = new \DateTimeImmutable('2026-01-01 10:05:00'),
    ): WorkerRun {
        $run = new WorkerRun(
            project: $this->project,
            bridgeId: $bridgeId ?? Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v7(),
            cardNumber: 7,
            workKind: 'implement',
            state: $state,
            startedAt: $startedAt,
            endedAt: $endedAt,
            receivedAt: new \DateTimeImmutable('2026-01-01 10:05:01'),
            kind: WorkerRunKind::Worker,
            ruleId: $ruleId,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function usage(
        WorkerRun $run,
        string $model,
        ?string $costUsd,
        int $outputTokens = 20,
        WorkerRunUsageSource $source = WorkerRunUsageSource::Reported,
    ): void {
        $run->usageSource = $source;
        $this->em->persist(new WorkerRunUsage($run, $run->project, $run->subjectType, $run->subjectId, $run->workKind, $model, $source, 100, $outputTokens, 300, 40, $costUsd));
        $this->em->flush();
    }

    /** Whatever wrote the rows before, the backfill alone fills them here. */
    private function backfillTwice(): void
    {
        $this->connection->executeStatement('DELETE FROM bridge_worker_run_facts');
        $runs = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs');

        $this->connection->executeStatement(Version20261006005345::BACKFILL_SQL);
        self::assertSame($runs, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));
        $this->connection->executeStatement(Version20261006005345::BACKFILL_SQL);
        self::assertSame($runs, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));
    }

    /** @return array<string, mixed> */
    private function fact(WorkerRun $run): array
    {
        $fact = $this->connection->fetchAssociative(
            'SELECT * FROM bridge_worker_run_facts WHERE run_id = :id',
            ['id' => (string) $run->id],
        );
        self::assertIsArray($fact);
        unset($fact['run_id']);

        return $fact;
    }
}
