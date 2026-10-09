<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Repository\WorkflowSlotLinkRepository;
use App\Tests\Module\Workflow\WorkflowProjects;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkflowSlotLinkRepositoryTest extends KernelTestCase
{
    use WorkflowProjects;

    public function test_a_linked_column_gives_its_slot_and_the_slot_gives_its_column(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('slot-link-read');
        $other = $this->workflowProject('slot-link-other');
        $this->bindLifecycle($project);
        $this->bindLifecycle($other);
        $repository = $this->repository();

        self::assertSame('implementation', $repository->findSlotKeyForColumn($project, $this->column($project, 'in-progress')));
        self::assertSame((string) $this->column($project, 'tech-design')->id, (string) $repository->findColumnForSlot($project, 'tech-design')?->id);
    }

    public function test_an_unlinked_column_and_an_unknown_slot_give_null(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('slot-link-none');
        $other = $this->workflowProject('slot-link-foreign');
        $this->bindLifecycle($project);
        $this->bindLifecycle($other);
        $repository = $this->repository();

        self::assertNull($repository->findSlotKeyForColumn($project, $this->column($project, 'done')));
        self::assertNull($repository->findSlotKeyForColumn($project, $this->column($other, 'in-progress')));
        self::assertNull($repository->findColumnForSlot($project, 'no-such-slot'));
    }

    public function test_the_columns_by_slot_are_the_project_s_own_and_arrive_loaded(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('slot-link-all');
        $other = $this->workflowProject('slot-link-all-other');
        $this->bindLifecycle($project);
        $this->bindLifecycle($other);
        $deleted = $this->column($project, 'tech-design');
        $deletedId = $deleted->id ?? throw new \LogicException();
        $this->em()->remove($deleted);
        $this->em()->flush();
        $this->repository()->clearColumn($deletedId);
        $this->em()->clear();
        $project = $this->em()->find(Project::class, $project->id) ?? throw new \LogicException();

        $columns = $this->repository()->findColumnsBySlot($project);

        ksort($columns);
        self::assertSame(['implementation', 'in-review', 'next', 'product-design', 'tech-design'], array_keys($columns));
        self::assertNull($columns['tech-design']);
        self::assertSame('in-progress', $columns['implementation']?->slug);
        foreach (array_filter($columns) as $column) {
            self::assertFalse($this->em()->getUnitOfWork()->isUninitializedObject($column));
            self::assertSame((string) $project->id, (string) $column->project->id);
        }
    }

    private function repository(): WorkflowSlotLinkRepository
    {
        $repository = self::getContainer()->get(WorkflowSlotLinkRepository::class);
        self::assertInstanceOf(WorkflowSlotLinkRepository::class, $repository);

        return $repository;
    }
}
