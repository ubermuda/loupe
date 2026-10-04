<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261003023648;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261003023648.php';

final class WorkerRunWorkKindBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_the_work_kind_comes_from_the_rule_name(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'work-kind-backfill@example.com');
        $project = $this->project($em, $owner, 'Work kind backfill');
        $rows = [
            ['work:implement', WorkerRunKind::Worker, 'implement'],
            ['implement-on-move', WorkerRunKind::Worker, null],
            ['work:review', WorkerRunKind::Interactive, 'review'],
            ['brainstorm', WorkerRunKind::Interactive, 'brainstorm'],
        ];
        $runs = [];
        foreach ($rows as [$ruleName, $kind, $expected]) {
            $run = $this->seedRun($em, $project, workKind: null, kind: $kind);
            $this->seedUsage($em, $run);
            $runs[] = [$ruleName, (string) $run->id, $expected];
        }
        $em->clear();
        $connection = $em->getConnection();
        foreach ($runs as [$ruleName, $id]) {
            $connection->executeStatement('UPDATE bridge_worker_runs SET rule_name = ? WHERE id = ?', [$ruleName, $id]);
            $connection->executeStatement('UPDATE bridge_worker_run_usage SET rule_name = ? WHERE run_id = ?', [$ruleName, $id]);
        }

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20261003023648($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        foreach ($runs as [$ruleName, $id, $expected]) {
            self::assertSame($expected, $connection->fetchOne('SELECT work_kind FROM bridge_worker_runs WHERE id = ?', [$id]), $ruleName);
            self::assertSame($expected, $connection->fetchOne('SELECT work_kind FROM bridge_worker_run_usage WHERE run_id = ?', [$id]), $ruleName);
        }
    }
}
