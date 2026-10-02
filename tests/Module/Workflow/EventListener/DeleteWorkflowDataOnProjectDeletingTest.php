<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\EventListener;

use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectDeleter;
use App\Module\Workflow\EventListener\DeleteWorkflowDataOnProjectDeleting;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeleteWorkflowDataOnProjectDeletingTest extends KernelTestCase
{
    use WorkflowProjects;

    /** The foreign keys cascade too, so the project row stays to prove that the listener deletes the rows. */
    public function test_the_listener_deletes_the_links_and_the_binding_and_spares_a_sibling(): void
    {
        self::bootKernel();
        $doomed = $this->workflowProject('workflow-delete');
        $spared = $this->workflowProject('workflow-spared');
        $this->bindLifecycle($doomed);
        $this->bindLifecycle($spared);
        self::assertSame([1, 5], $this->rowCounts((string) $doomed->id));

        $listener = self::getContainer()->get(DeleteWorkflowDataOnProjectDeleting::class);
        self::assertInstanceOf(DeleteWorkflowDataOnProjectDeleting::class, $listener);
        $listener(new ProjectDeleting($doomed));

        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM projects WHERE id = :id', ['id' => (string) $doomed->id]));
        self::assertSame([0, 0], $this->rowCounts((string) $doomed->id));
        self::assertSame([1, 5], $this->rowCounts((string) $spared->id));
    }

    public function test_no_workflow_row_outlives_a_deleted_project(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('workflow-deleter');
        $this->bindLifecycle($project);
        $projectId = (string) $project->id;
        self::assertSame([1, 5], $this->rowCounts($projectId));

        $deleter = self::getContainer()->get(ProjectDeleter::class);
        self::assertInstanceOf(ProjectDeleter::class, $deleter);
        $deleter->delete($project);

        self::assertSame([0, 0], $this->rowCounts($projectId));
    }

    /** @return array{int, int} the binding rows and the link rows of the project */
    private function rowCounts(string $projectId): array
    {
        $connection = $this->em()->getConnection();
        $id = ['id' => $projectId];

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_bindings WHERE project_id = :id', $id),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM workflow_slot_links WHERE project_id = :id', $id),
        ];
    }
}
