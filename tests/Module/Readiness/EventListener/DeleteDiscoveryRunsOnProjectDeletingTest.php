<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Readiness\EventListener\DeleteDiscoveryRunsOnProjectDeleting;
use App\Tests\Module\Readiness\DiscoveryScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteDiscoveryRunsOnProjectDeletingTest extends KernelTestCase
{
    use DiscoveryScenario;

    /** The foreign keys cascade too, so the project row stays to prove that the listener deletes the rows. */
    public function test_the_listener_deletes_the_runs_of_the_project_and_spares_a_sibling(): void
    {
        self::bootKernel();
        $doomed = $this->workflowProject('discovery-delete');
        $spared = $this->workflowProject('discovery-spared');
        $this->discoveryRun($this->discoveryCard($doomed));
        $this->discoveryRun($this->discoveryCard($spared));

        $listener = self::getContainer()->get(DeleteDiscoveryRunsOnProjectDeleting::class);
        self::assertInstanceOf(DeleteDiscoveryRunsOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame(0, $this->runCount((string) $doomed->id));
        self::assertSame(1, $this->runCount((string) $spared->id));
    }

    public function test_no_run_outlives_a_deleted_project(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('discovery-deleter');
        $this->discoveryRun($this->discoveryCard($project));
        $projectId = (string) $project->id;
        self::assertSame(1, $this->runCount($projectId));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        self::assertSame(0, $this->runCount($projectId));
    }

    private function runCount(string $projectId): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM discovery_runs WHERE project_id = :id', ['id' => $projectId]);
    }
}
