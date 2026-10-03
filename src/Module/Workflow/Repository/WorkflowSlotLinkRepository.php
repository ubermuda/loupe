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

    /** @return array<string, ?BoardColumn> each slot key, mapped to its column, or null when the column is deleted */
    public function findColumnsBySlot(Project $project): array
    {
        /** @var list<WorkflowSlotLink> $links */
        $links = $this->createQueryBuilder('l')
            ->leftJoin('l.column', 'c')
            ->addSelect('c')
            ->where('l.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        $columns = [];
        foreach ($links as $link) {
            $columns[$link->slotKey] = $link->column;
        }

        return $columns;
    }

    public function findColumnForSlot(Project $project, string $slotKey): ?BoardColumn
    {
        return $this->findOneBy(['project' => $project, 'slotKey' => $slotKey])?->column;
    }
}
