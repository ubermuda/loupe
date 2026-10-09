<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads and seeds the columns of a board for the workflow. Board implements it. */
interface BoardColumns
{
    /** @return list<ColumnView> in board order */
    public function forProject(Uuid $projectId): array;

    /**
     * Reads the rows again, so a caller that holds the project lock sees what another transaction committed.
     *
     * @return list<ColumnView> in board order
     */
    public function forProjectFresh(Uuid $projectId): array;

    public function find(Uuid $columnId): ?ColumnView;

    /**
     * Persists and never flushes, so the columns commit with the project.
     *
     * @param list<array{slug: non-empty-string, label: string, tone: LabelTone}> $middle
     *
     * @return list<ColumnView> the Backlog, the middle columns and Done
     */
    public function seedBetweenBacklogAndDone(Uuid $projectId, array $middle): array;
}
