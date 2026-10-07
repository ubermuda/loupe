<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerRunFactWriterTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_writes_the_named_runs_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-writer@example.com'), 'Facts Writer');
        $named = $this->seedRun($em, $project);
        $this->seedRun($em, $project, cardNumber: 2);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts');

        $this->writer()->upsert([]);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_facts'));

        $this->writer()->upsert([$named->id ?? throw new \LogicException('The run has no id.')]);

        self::assertSame(
            [(string) $named->id],
            $connection->fetchFirstColumn('SELECT run_id FROM bridge_worker_run_facts'),
        );
    }

    public function test_it_copies_the_peak_context_of_the_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-writer-peak@example.com'), 'Facts Writer Peak');
        $known = $this->seedRun($em, $project);
        $unknown = $this->seedRun($em, $project, cardNumber: 2);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts');
        $connection->executeStatement('UPDATE bridge_worker_runs SET peak_context_tokens = 3000000000 WHERE id = ?', [(string) $known->id]);

        $this->writer()->upsert([
            $known->id ?? throw new \LogicException('The run has no id.'),
            $unknown->id ?? throw new \LogicException('The run has no id.'),
        ]);

        self::assertEquals(
            [(string) $known->id => 3_000_000_000, (string) $unknown->id => null],
            $connection->fetchAllKeyValue('SELECT run_id, peak_context_tokens FROM bridge_worker_run_facts'),
        );
    }

    public function test_it_reads_the_host_samples_inside_the_run_window(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-host@example.com');
        $project = $this->project($em, $owner, 'Facts Writer Host');
        $battery = $this->seedBridge($em, $owner);
        $mains = $this->seedBridge($em, $owner);
        $unknown = $this->seedBridge($em, $owner);
        $onBattery = $this->seedRun($em, $project, bridgeId: $battery->id);
        $onMains = $this->seedRun($em, $project, cardNumber: 2, bridgeId: $mains->id);
        $powerUnknown = $this->seedRun($em, $project, cardNumber: 3, bridgeId: $unknown->id);
        $this->seedHostSample($battery, '2026-01-01 09:59:59', cpuPct: [100.0], memUsed: 9_000_000_000, swapUsed: 900, onAc: false);
        $this->seedHostSample($battery, '2026-01-01 10:00:00', cpuPct: [10.0, 30.0], memUsed: 1000, swapUsed: 5, onAc: true);
        $this->seedHostSample($battery, '2026-01-01 10:03:00', cpuPct: [50.0], memUsed: 5_000_000_000, swapUsed: 2, onAc: false);
        $this->seedHostSample($battery, '2026-01-01 10:04:00', cpuPct: [], memUsed: 10, swapUsed: 1, onAc: null);
        $this->seedHostSample($battery, '2026-01-01 10:05:01', cpuPct: [100.0], memUsed: 9_000_000_000, swapUsed: 900, onAc: false);
        $this->seedHostSample($mains, '2026-01-01 10:05:00', onAc: true);
        $this->seedHostSample($unknown, '2026-01-01 10:01:00', onAc: null);

        $this->writer()->upsert([self::id($onBattery), self::id($onMains), self::id($powerUnknown)]);

        self::assertEquals([
            (string) $onBattery->id => ['mean_cpu_pct' => 35.0, 'peak_mem_bytes' => 5_000_000_000, 'peak_swap_bytes' => 5, 'concurrent_runs' => 1, 'on_battery' => true],
            (string) $onMains->id => ['mean_cpu_pct' => 20.0, 'peak_mem_bytes' => 1000, 'peak_swap_bytes' => 0, 'concurrent_runs' => 1, 'on_battery' => false],
            (string) $powerUnknown->id => ['mean_cpu_pct' => 20.0, 'peak_mem_bytes' => 1000, 'peak_swap_bytes' => 0, 'concurrent_runs' => 1, 'on_battery' => null],
        ], $this->hostColumns());
    }

    public function test_a_run_with_no_sample_has_no_host_metrics_and_still_counts_itself(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'facts-writer-no-sample@example.com'), 'Facts Writer No Sample');
        $run = $this->seedRun($em, $project);

        $this->writer()->upsert([self::id($run)]);

        self::assertEquals([
            (string) $run->id => ['mean_cpu_pct' => null, 'peak_mem_bytes' => null, 'peak_swap_bytes' => null, 'concurrent_runs' => 1, 'on_battery' => null],
        ], $this->hostColumns());
    }

    public function test_runs_that_overlap_on_one_bridge_count_each_other(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-overlap@example.com');
        $project = $this->project($em, $owner, 'Facts Writer Overlap');
        $secondProject = $this->project($em, $owner, 'Facts Writer Overlap Two');
        $bridge = $this->seedBridge($em, $owner);
        $first = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $second = $this->seedRun($em, $secondProject, cardNumber: 2, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 10:10:00'));
        $handoff = $this->seedRun($em, $project, cardNumber: 3, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 10:12:00'));
        $later = $this->seedRun($em, $project, cardNumber: 4, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 11:05:00'));
        $open = $this->seedRun($em, $project, cardNumber: 5, bridgeId: $bridge->id);
        $lost = $this->seedRun($em, $project, cardNumber: 6, bridgeId: $bridge->id);
        $connection = $em->getConnection();
        $connection->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 10:04:00' WHERE id = ?", [(string) $second->id]);
        // Starts the second the second run ends, so the two never run together.
        $connection->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 10:10:00' WHERE id = ?", [(string) $handoff->id]);
        $connection->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 11:00:00' WHERE id = ?", [(string) $later->id]);
        // Still open, so its window reaches every later end.
        $connection->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 10:08:00', ended_at = NULL, state = 'running' WHERE id = ?", [(string) $open->id]);
        // Lost with no end, so it ran for an unknown time and counts for nothing.
        $connection->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 10:08:00', ended_at = NULL, state = 'lost' WHERE id = ?", [(string) $lost->id]);

        $this->writer()->upsert([self::id($first), self::id($second), self::id($handoff), self::id($later)]);

        self::assertEquals(
            [(string) $first->id => 2, (string) $second->id => 3, (string) $handoff->id => 2, (string) $later->id => 2],
            array_map(static fn (array $row): mixed => $row['concurrent_runs'], array_diff_key($this->hostColumns(), [(string) $open->id => true, (string) $lost->id => true])),
        );
    }

    /** A bridge id is unique per owner alone, so another account's bridge with the same id is another machine. */
    public function test_another_owners_bridge_with_the_same_id_is_left_out(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-owner@example.com');
        $other = $this->user($em, 'facts-writer-other-owner@example.com');
        $bridge = $this->seedBridge($em, $owner);
        $otherBridge = $this->seedBridge($em, $other, $bridge->id);
        $run = $this->seedRun($em, $this->project($em, $owner, 'Facts Writer Mine'), bridgeId: $bridge->id);
        $this->seedRun($em, $this->project($em, $other, 'Facts Writer Theirs'), bridgeId: $bridge->id);
        $this->seedHostSample($otherBridge, '2026-01-01 10:01:00', memUsed: 7_000_000, onAc: false);

        $this->writer()->upsert([self::id($run)]);

        self::assertEquals(
            ['mean_cpu_pct' => null, 'peak_mem_bytes' => null, 'peak_swap_bytes' => null, 'concurrent_runs' => 1, 'on_battery' => null],
            $this->hostColumns()[(string) $run->id],
        );
    }

    public function test_a_later_overlapping_run_recomputes_the_count_of_an_ended_run(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-recount@example.com');
        $project = $this->project($em, $owner, 'Facts Writer Recount');
        $bridge = $this->seedBridge($em, $owner);
        $ended = $this->seedRun($em, $project, bridgeId: $bridge->id);
        $this->writer()->upsert([self::id($ended)]);
        self::assertSame(1, $this->hostColumns()[(string) $ended->id]['concurrent_runs']);

        $late = $this->seedRun($em, $project, cardNumber: 2, bridgeId: $bridge->id, endedAt: new \DateTimeImmutable('2026-01-01 10:10:00'));
        $em->getConnection()->executeStatement("UPDATE bridge_worker_runs SET started_at = '2026-01-01 10:04:00' WHERE id = ?", [(string) $late->id]);
        $this->writer()->upsert([self::id($late)]);

        self::assertSame(2, $this->hostColumns()[(string) $ended->id]['concurrent_runs']);
    }

    public function test_a_run_of_another_owner_on_the_same_bridge_id_leaves_an_ended_run_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-recount-mine@example.com');
        $other = $this->user($em, 'facts-writer-recount-theirs@example.com');
        $bridge = $this->seedBridge($em, $owner);
        $this->seedBridge($em, $other, $bridge->id);
        $this->seedRun($em, $this->project($em, $owner, 'Facts Writer Recount Mine'), bridgeId: $bridge->id);
        $theirs = $this->seedRun($em, $this->project($em, $other, 'Facts Writer Recount Theirs'), bridgeId: $bridge->id);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_run_facts');

        $this->writer()->upsert([self::id($theirs)]);

        self::assertSame([(string) $theirs->id], $connection->fetchFirstColumn('SELECT run_id FROM bridge_worker_run_facts'));
    }

    public function test_a_run_with_no_end_has_no_host_metrics(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'facts-writer-running@example.com');
        $bridge = $this->seedBridge($em, $owner);
        $run = $this->seedRun($em, $this->project($em, $owner, 'Facts Writer Running'), bridgeId: $bridge->id);
        $em->getConnection()->executeStatement('UPDATE bridge_worker_runs SET ended_at = NULL WHERE id = ?', [(string) $run->id]);
        $this->seedHostSample($bridge, '2026-01-01 10:01:00', onAc: false);

        $this->writer()->upsert([self::id($run)]);

        self::assertEquals([
            (string) $run->id => ['mean_cpu_pct' => null, 'peak_mem_bytes' => null, 'peak_swap_bytes' => null, 'concurrent_runs' => null, 'on_battery' => null],
        ], $this->hostColumns());
    }

    private static function id(WorkerRun $run): \Symfony\Component\Uid\Uuid
    {
        return $run->id ?? throw new \LogicException('The run has no id.');
    }

    /** @return array<string, array<string, mixed>> */
    private function hostColumns(): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT run_id, mean_cpu_pct, peak_mem_bytes, peak_swap_bytes, concurrent_runs, on_battery FROM bridge_worker_run_facts',
        );
        $columns = [];
        foreach ($rows as $row) {
            $runId = $row['run_id'];
            self::assertIsString($runId);
            unset($row['run_id']);
            $columns[$runId] = $row;
        }

        return $columns;
    }

    private function writer(): WorkerRunFactWriter
    {
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);

        return $writer;
    }
}
