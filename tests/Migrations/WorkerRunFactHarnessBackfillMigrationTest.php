<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261008124047;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261008124047.php';

final class WorkerRunFactHarnessBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_fact_takes_the_harness_of_its_run_and_an_agent_fact_with_none_ran_claude_code(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'fact-harness-backfill@example.com'), 'Fact harness backfill');
        $codex = $this->seedRun($em, $project, harness: 'codex', account: 'work');
        $unnamed = $this->seedRun($em, $project, cardNumber: 2);
        $swept = $this->seedRun($em, $project, cardNumber: 3);
        $command = $this->seedRun($em, $project, cardNumber: 4, kind: WorkerRunKind::Command);
        $connection = $em->getConnection();
        $connection->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $swept->id]);
        $em->clear();

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261008124047($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        self::assertSame([
            (string) $codex->id => ['codex', 'work'],
            (string) $unnamed->id => ['claude-code', null],
            (string) $swept->id => ['claude-code', null],
            (string) $command->id => [null, null],
        ], array_map(
            static fn (array $row): array => [$row['harness'], $row['account']],
            $connection->fetchAllAssociativeIndexed('SELECT run_id, harness, account FROM bridge_worker_run_facts WHERE project_id = ? ORDER BY run_id', [(string) $project->id]),
        ));
    }
}
