<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260923185847;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20260923185847.php';

final class WorkerRunStateBackfillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_an_existing_run_gets_its_state_and_its_start_and_outcome_rows(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'state-backfill@example.com');
        $project = $this->project($em, $owner, 'State backfill');
        $succeeded = $this->seedRun($em, $project, cardNumber: 1, exitCode: 0);
        $failed = $this->seedRun($em, $project, cardNumber: 2, exitCode: 3);
        $em->flush();
        $em->clear();
        $connection = $em->getConnection();

        foreach (['down', 'up'] as $direction) {
            $migration = new Version20260923185847($connection, new NullLogger());
            $migration->{$direction}(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
            }
        }

        foreach ([[$succeeded->id, 'succeeded'], [$failed->id, 'failed']] as [$id, $state]) {
            self::assertSame($state, $connection->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = ?', [(string) $id]));
            self::assertSame(
                [
                    ['state' => 'running', 'at' => '2026-01-01 10:00:00'],
                    ['state' => $state, 'at' => '2026-01-01 10:05:00'],
                ],
                $connection->fetchAllAssociative('SELECT state, at FROM bridge_worker_run_states WHERE run_id = ? ORDER BY at', [(string) $id]),
            );
        }
    }
}
