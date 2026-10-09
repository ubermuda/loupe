<?php

declare(strict_types=1);

namespace App\Module\Workflow\Repository;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\BoardColumns;
use App\Module\Workflow\Contract\ColumnRef;
use App\Module\Workflow\Contract\ColumnView;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<WorkflowSlotLink> */
class WorkflowSlotLinkRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly BoardColumns $boardColumns,
    ) {
        parent::__construct($registry, WorkflowSlotLink::class);
    }

    public function findSlotKeyForColumn(Project $project, ColumnRef $column): ?string
    {
        return $this->findSlotKeyForColumnId($project, $column->id);
    }

    public function findSlotKeyForColumnId(Project $project, ?Uuid $columnId): ?string
    {
        return $this->findOneBy(['project' => $project, 'columnId' => $columnId])?->slotKey;
    }

    /** @return array<string, ?ColumnView> each slot key, mapped to its column, or null when the column is deleted */
    public function findColumnsBySlot(Project $project): array
    {
        /** @var list<WorkflowSlotLink> $links */
        $links = $this->findBy(['project' => $project]);
        $byId = [];
        foreach ($this->boardColumns->forProject($project->id ?? throw new \LogicException('The project is not persisted.')) as $column) {
            $byId[$column->id->toRfc4122()] = $column;
        }

        $columns = [];
        foreach ($links as $link) {
            $columns[$link->slotKey] = null === $link->columnId ? null : ($byId[$link->columnId->toRfc4122()] ?? null);
        }

        return $columns;
    }

    public function findColumnIdForSlot(Project $project, string $slotKey): ?Uuid
    {
        return $this->findOneBy(['project' => $project, 'slotKey' => $slotKey])?->columnId;
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
