<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Service;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Service\WorkerRunAccountPurger;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\Bridge\BridgeScenario;
use DoctrineMigrations\Version20261006005345;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../../../migrations/Version20261006005345.php';

final class WorkerRunAccountPurgerTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_it_takes_the_runs_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-leaving@example.com');
        $staying = $this->user($em, 'runs-purge-staying@example.com');
        $this->seedRun($em, $this->project($em, $leaving, 'Leaving Runs'));
        $keptRunId = $this->seedRun($em, $this->project($em, $staying, 'Staying Runs'))->id;

        $this->purge($leaving);

        $em->clear();
        /** @var list<WorkerRun> $remaining */
        $remaining = $em->createQuery('SELECT r FROM '.WorkerRun::class.' r')->getResult();
        self::assertCount(1, $remaining);
        self::assertSame((string) $keptRunId, (string) $remaining[0]->id);
    }

    public function test_it_takes_runs_that_resume_one_another(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-linked@example.com');
        $project = $this->project($em, $leaving, 'Linked Runs');
        $first = $this->seedRun($em, $project);
        $this->seedRun($em, $project, cardNumber: 2)->continuesRun = $first;
        $em->flush();

        $this->purge($leaving);

        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs'),
        );
    }

    public function test_the_tool_calls_go_with_the_runs_of_the_departing_account(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'tool-calls-purge-leaving@example.com');
        $staying = $this->user($em, 'tool-calls-purge-staying@example.com');
        $this->seedToolCall($this->seedRun($em, $this->project($em, $leaving, 'Leaving Tool Calls')));
        $keptRun = $this->seedRun($em, $this->project($em, $staying, 'Staying Tool Calls'));
        $this->seedToolCall($keptRun);
        self::assertSame(2, $this->countToolCalls($em));

        $this->purge($leaving);

        self::assertSame(1, $this->countToolCalls($em));
        self::assertSame((string) $keptRun->id, $em->getConnection()->fetchOne('SELECT run_id FROM bridge_worker_run_tool_calls'));
    }

    /** A usage row whose run the retention sweep took still belongs to the account. */
    public function test_it_takes_the_usage_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'usage-purge-leaving@example.com');
        $staying = $this->user($em, 'usage-purge-staying@example.com');
        $this->seedUsage($em, $this->seedRun($em, $this->project($em, $leaving, 'Leaving Usage')));
        $orphan = $this->seedRun($em, $this->project($em, $leaving, 'Leaving Orphan Usage'));
        $this->seedUsage($em, $orphan);
        $em->getConnection()->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $orphan->id]);
        $keptUsageId = $this->seedUsage($em, $this->seedRun($em, $this->project($em, $staying, 'Staying Usage')))->id;

        $this->purge($leaving);

        self::assertSame(1, $this->countUsage($em));
        self::assertSame((string) $keptUsageId, $em->getConnection()->fetchOne('SELECT id FROM bridge_worker_run_usage'));
    }

    /** A fact row outlives its run, like a usage row. */
    public function test_it_takes_the_facts_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'facts-purge-leaving@example.com');
        $staying = $this->user($em, 'facts-purge-staying@example.com');
        $orphan = $this->seedRun($em, $this->project($em, $leaving, 'Leaving Facts'));
        $kept = $this->seedRun($em, $this->project($em, $staying, 'Staying Facts'));
        $connection = $em->getConnection();
        $connection->executeStatement(Version20261006005345::BACKFILL_SQL);
        $connection->executeStatement('DELETE FROM bridge_worker_runs WHERE id = ?', [(string) $orphan->id]);

        $this->purge($leaving);

        self::assertSame(
            [(string) $kept->id],
            $connection->fetchFirstColumn('SELECT run_id FROM bridge_worker_run_facts'),
        );
    }

    public function test_it_takes_the_experiment_pins_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'pins-purge-leaving@example.com');
        $staying = $this->user($em, 'pins-purge-staying@example.com');
        $em->persist(new ExperimentPin($this->project($em, $leaving, 'Leaving Pins'), Uuid::v7(), 'impl-model', 'opus'));
        $keptPin = new ExperimentPin($this->project($em, $staying, 'Staying Pins'), Uuid::v7(), 'impl-model', 'opus');
        $em->persist($keptPin);
        $em->flush();

        $this->purge($leaving);

        self::assertSame(
            [(string) $keptPin->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_experiment_pins'),
        );
    }

    public function test_it_takes_the_experiment_definitions_of_the_departing_account_alone(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'definitions-purge-leaving@example.com');
        $staying = $this->user($em, 'definitions-purge-staying@example.com');
        $em->persist(new ExperimentDefinition($this->project($em, $leaving, 'Leaving Definitions'), 'impl-model', [['name' => 'opus', 'weight' => 1]]));
        $keptDefinition = new ExperimentDefinition($this->project($em, $staying, 'Staying Definitions'), 'impl-model', [['name' => 'opus', 'weight' => 1]]);
        $em->persist($keptDefinition);
        $em->flush();

        $this->purge($leaving);

        self::assertSame(
            [(string) $keptDefinition->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_experiment_definitions'),
        );
    }

    public function test_it_takes_the_holds_of_the_departing_account_and_unnames_it_elsewhere(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'holds-purge-leaving@example.com');
        $staying = $this->user($em, 'holds-purge-staying@example.com');
        $now = new \DateTimeImmutable();
        $em->persist(new CardHold($this->project($em, $leaving, 'Leaving Holds'), Uuid::v7(), $staying, $now));
        $foreign = new CardHold($this->project($em, $staying, 'Staying Holds'), Uuid::v7(), $leaving, $now);
        $em->persist($foreign);
        $em->flush();
        $em->clear();

        $this->purge($leaving);

        self::assertSame(
            [['id' => (string) $foreign->id, 'held_by_id' => null]],
            $em->getConnection()->fetchAllAssociative('SELECT id, held_by_id FROM bridge_card_holds'),
        );
    }

    /** ProjectAccountPurger clears the EntityManager, so this slot always gets a detached user. */
    public function test_it_purges_a_detached_user(): void
    {
        self::bootKernel();
        $em = $this->em();
        $leaving = $this->user($em, 'runs-purge-detached@example.com');
        $this->seedRun($em, $this->project($em, $leaving, 'Detached Runs'));
        $em->clear();

        $this->purge($leaving);

        self::assertSame(
            0,
            (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM bridge_worker_runs'),
        );
    }

    /** A purger that is not tagged never runs, and no test of its statements would notice. */
    public function test_it_is_registered_after_the_project_purger(): void
    {
        self::bootKernel();
        $accountPurger = static::getContainer()->get(AccountPurger::class);
        $purgers = new \ReflectionProperty(AccountPurger::class, 'purgers')->getValue($accountPurger);
        self::assertIsArray($purgers);

        $ordered = array_values(array_map(
            static fn (object $purger): string => $purger::class,
            array_filter($purgers, is_object(...)),
        ));

        self::assertContains(WorkerRunAccountPurger::class, $ordered);
        self::assertGreaterThan(
            array_search(ProjectAccountPurger::class, $ordered, true),
            array_search(WorkerRunAccountPurger::class, $ordered, true),
        );
    }

    private function purge(User $user): void
    {
        new WorkerRunAccountPurger($this->em())->purge($user, new AccountDeletionCleanup());
    }
}
