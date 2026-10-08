<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261007170915;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261007170915.php';

final class WorkerRunHarnessBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_worker_run_and_an_interactive_run_ran_claude_code_and_a_command_run_ran_no_harness(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'harness-backfill@example.com');
        $project = $this->project($em, $owner, 'Harness backfill');
        $runs = [];
        foreach ([[WorkerRunKind::Worker, 'claude-code'], [WorkerRunKind::Interactive, 'claude-code'], [WorkerRunKind::Command, null]] as [$kind, $expected]) {
            $runs[] = [(string) $this->seedRun($em, $project, kind: $kind)->id, $kind, $expected];
        }
        $em->clear();
        $connection = $em->getConnection();

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261007170915($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        foreach ($runs as [$id, $kind, $expected]) {
            self::assertSame(
                [$expected, null, null, null],
                array_values($connection->fetchAssociative('SELECT harness, account, model, harness_session_id FROM bridge_worker_runs WHERE id = ?', [$id]) ?: []),
                $kind->value,
            );
        }
    }
}
