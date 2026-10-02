<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Repository;

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

    private function repository(): WorkflowSlotLinkRepository
    {
        $repository = self::getContainer()->get(WorkflowSlotLinkRepository::class);
        self::assertInstanceOf(WorkflowSlotLinkRepository::class, $repository);

        return $repository;
    }
}
