<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Module\Bridge\BridgeScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

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
