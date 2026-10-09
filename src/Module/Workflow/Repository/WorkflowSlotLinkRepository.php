<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowSlotLink> */
class WorkflowSlotLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowSlotLink::class);
    }

    public function findSlotKeyForColumn(Project $project, BoardColumn $column): ?string
    {
        return $this->findOneBy(['project' => $project, 'columnId' => $column->id])?->slotKey;
    }

    /** @return array<string, ?BoardColumn> each slot key, mapped to its column, or null when the column is deleted */
    public function findColumnsBySlot(Project $project): array
    {
        /** @var list<WorkflowSlotLink> $links */
        $links = $this->findBy(['project' => $project]);
        $columnIds = array_values(array_filter(array_map(static fn (WorkflowSlotLink $link): ?Uuid => $link->columnId, $links)));
        $byId = [];
        if ([] !== $columnIds) {
            /** @var list<BoardColumn> $found */
            $found = $this->getEntityManager()->createQueryBuilder()
                ->select('c')
                ->from(BoardColumn::class, 'c')
                ->where('c.id IN (:ids)')
                ->setParameter('ids', $columnIds)
                ->getQuery()
                ->getResult();
            foreach ($found as $column) {
                $byId[(string) $column->id?->toRfc4122()] = $column;
            }
        }

        $columns = [];
        foreach ($links as $link) {
            $columns[$link->slotKey] = null === $link->columnId ? null : ($byId[$link->columnId->toRfc4122()] ?? null);
        }

        return $columns;
    }

    public function findColumnForSlot(Project $project, string $slotKey): ?BoardColumn
    {
        $columnId = $this->findOneBy(['project' => $project, 'slotKey' => $slotKey])?->columnId;

        return null === $columnId ? null : $this->getEntityManager()->find(BoardColumn::class, $columnId);
    }

    /** One statement, so it joins a caller's transaction. */
    public function clearColumn(Uuid $columnId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE workflow_slot_links SET column_id = NULL WHERE column_id = :column',
            ['column' => $columnId->toRfc4122()],
        );
    }
}
