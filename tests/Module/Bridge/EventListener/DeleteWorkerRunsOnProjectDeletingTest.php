<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\ExperimentDefinition;
use App\Module\Bridge\Entity\ExperimentPin;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Without the listener the run rows outlive the project, and the foreign key
 * makes the project delete fail outright.
 */
final class DeleteWorkerRunsOnProjectDeletingTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_deleting_a_project_takes_its_runs_and_leaves_another_projects_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'runs-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Runs');
        $kept = $this->project($em, $owner, 'Kept Runs');
        $this->seedRun($em, $doomed);
        $this->seedRun($em, $doomed, cardNumber: 2);
        $keptRunId = $this->seedRun($em, $kept)->id;

        self::assertSame(3, $this->countRuns());

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        $remaining = $this->allRuns();
        self::assertCount(1, $remaining);
        self::assertSame((string) $keptRunId, (string) $remaining[0]->id);
    }

    /** One statement deletes both ends of a resume link, so the SET NULL never meets a missing row. */
    public function test_deleting_a_project_takes_runs_that_resume_one_another(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'runs-linked-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Linked Runs');
        $kept = $this->project($em, $owner, 'Kept Linked Runs');
        $first = $this->seedRun($em, $doomed);
        $this->seedRun($em, $doomed, cardNumber: 2)->continuesRun = $first;
        $em->flush();
        $keptRunId = $this->seedRun($em, $kept)->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        $remaining = $this->allRuns();
        self::assertCount(1, $remaining);
        self::assertSame((string) $keptRunId, (string) $remaining[0]->id);
    }

    /** The listener deletes with DQL, which skips the ORM, so only the foreign key can take the history. */
    public function test_deleting_a_project_takes_the_state_history_of_its_runs(): void
    {
        self::bootKernel();
        $em = $this->em();
        $doomed = $this->project($em, $this->user($em, 'runs-history-delete@example.com'), 'Doomed History');
        $run = $this->seedRun($em, $doomed);
        $em->persist(new WorkerRunStateChange($run, WorkerRunState::Succeeded, new \DateTimeImmutable()));
        $em->flush();
        $connection = $em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_states'));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM bridge_worker_run_states'));
    }

    /** A usage row outlives its run, so the project delete must take it on its own. */
    public function test_deleting_a_project_takes_its_usage_and_leaves_another_projects_usage(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'usage-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Usage');
        $kept = $this->project($em, $owner, 'Kept Usage');
        $this->seedUsage($em, $this->seedRun($em, $doomed));
        $keptUsageId = $this->seedUsage($em, $this->seedRun($em, $kept))->id;

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(1, $this->countUsage($em));
        self::assertSame((string) $keptUsageId, $em->getConnection()->fetchOne('SELECT id FROM bridge_worker_run_usage'));
    }

    public function test_deleting_a_project_takes_its_experiment_pins_and_leaves_another_projects_pins(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'pins-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Pins');
        $kept = $this->project($em, $owner, 'Kept Pins');
        $em->persist(new ExperimentPin($doomed, Uuid::v7(), 'impl-model', 'opus'));
        $keptPin = new ExperimentPin($kept, Uuid::v7(), 'impl-model', 'opus');
        $em->persist($keptPin);
        $em->flush();

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(
            [(string) $keptPin->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_experiment_pins'),
        );
    }

    public function test_deleting_a_project_takes_its_experiment_definitions_and_leaves_another_projects_definitions(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'definitions-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Definitions');
        $kept = $this->project($em, $owner, 'Kept Definitions');
        $em->persist(new ExperimentDefinition($doomed, 'impl-model', [['name' => 'opus', 'weight' => 1]]));
        $keptDefinition = new ExperimentDefinition($kept, 'impl-model', [['name' => 'opus', 'weight' => 1]]);
        $em->persist($keptDefinition);
        $em->flush();

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(
            [(string) $keptDefinition->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_experiment_definitions'),
        );
    }

    public function test_deleting_a_project_takes_its_holds_and_leaves_another_projects_holds(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'holds-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Holds');
        $kept = $this->project($em, $owner, 'Kept Holds');
        $em->persist(new CardHold($doomed, Uuid::v7(), $owner, new \DateTimeImmutable()));
        $keptHold = new CardHold($kept, Uuid::v7(), $owner, new \DateTimeImmutable());
        $em->persist($keptHold);
        $em->flush();

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(
            [(string) $keptHold->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_card_holds'),
        );
    }

    public function test_deleting_a_project_takes_its_commands_and_leaves_another_projects_commands(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'commands-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Commands');
        $kept = $this->project($em, $owner, 'Kept Commands');
        $this->seedCommand($em, $this->seedRun($em, $doomed));
        $keptCommand = $this->seedCommand($em, $this->seedRun($em, $kept));
        self::assertSame(2, $this->countCommands($em));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(
            [(string) $keptCommand->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM bridge_commands'),
        );
    }

    public function test_deleting_a_project_takes_its_work_requests_and_leaves_another_projects_requests(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'work-requests-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Requests');
        $kept = $this->project($em, $owner, 'Kept Requests');
        $this->seedWorkRequest($em, $doomed);
        $keptRequest = $this->seedWorkRequest($em, $kept);

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(
            [(string) $keptRequest->id],
            $em->getConnection()->fetchFirstColumn('SELECT id FROM work_requests'),
        );
    }

    /**
     * One bridge follows several projects, so deleting one of them leaves the
     * bridge row and its list alone. The next heartbeat drops the id.
     */
    public function test_deleting_a_project_leaves_the_bridge_that_follows_it(): void
    {
        self::bootKernel();
        $em = $this->em();
        $owner = $this->user($em, 'bridge-survives-delete@example.com');
        $doomed = $this->project($em, $owner, 'Doomed Bridge Project');
        $kept = $this->project($em, $owner, 'Kept Bridge Project');
        $projects = [(string) $doomed->id, (string) $kept->id];
        $bridgeId = $this->seedBridge($em, $owner, projects: $projects)->id;
        $ownerId = $owner->id;
        $this->seedRun($em, $doomed);

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($doomed);

        self::assertSame(0, $this->countRuns());
        $em->clear();
        self::assertSame(
            json_encode($projects),
            $em->getConnection()->fetchOne('SELECT projects FROM bridges WHERE owner_id = ? AND id = ?', [(string) $ownerId, (string) $bridgeId]),
        );
    }

    /** @return list<WorkerRun> */
    private function allRuns(): array
    {
        /** @var list<WorkerRun> $runs */
        $runs = $this->em()->createQuery('SELECT r FROM '.WorkerRun::class.' r')->getResult();

        return $runs;
    }

    private function countRuns(): int
    {
        return \count($this->allRuns());
    }
}
