<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkflowSlotLink> */
class WorkflowSlotLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowSlotLink::class);
    }

    public function findSlotKeyForColumn(Project $project, BoardColumn $column): ?string
    {
        return $this->findOneBy(['project' => $project, 'column' => $column])?->slotKey;
    }

    public function findColumnForSlot(Project $project, string $slotKey): ?BoardColumn
    {
        return $this->findOneBy(['project' => $project, 'slotKey' => $slotKey])?->column;
    }
}
