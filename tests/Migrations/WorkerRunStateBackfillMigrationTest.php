<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\Repository\WorkerRunStateChangeRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
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
        $notStarted = $this->seedRun($em, $project, cardNumber: 3, exitCode: null, failureReason: 'spawn failed');
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
        self::assertSame(
            ['not-started'],
            $connection->fetchFirstColumn('SELECT state FROM bridge_worker_run_states WHERE run_id = ?', [(string) $notStarted->id]),
        );
    }

    /** A run that started and ended in one second still reads its start first. */
    public function test_two_states_of_one_second_read_in_the_order_they_happen(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'state-same-second@example.com');
        $project = $this->project($em, $owner, 'Same second');
        $run = $this->seedRun($em, $project);
        $at = new \DateTimeImmutable('2026-01-01 10:00:00');
        // The outcome first, so the insert order cannot be what puts the start first.
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::Succeeded, $at, $at));
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::Running, $at, $at));
        $em->flush();
        $em->clear();

        $repository = self::getContainer()->get(WorkerRunStateChangeRepository::class);
        self::assertInstanceOf(WorkerRunStateChangeRepository::class, $repository);
        $reloaded = $em->find(WorkerRun::class, $run->id);
        self::assertInstanceOf(WorkerRun::class, $reloaded);

        self::assertSame(
            [WorkerRunState::Running, WorkerRunState::Succeeded],
            array_map(static fn (WorkerRunStateChange $change): WorkerRunState => $change->state, $repository->findForRun($reloaded)),
        );
    }
}
