<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads cards for the workflow. Board implements it. */
interface CardDirectory
{
    public function find(Uuid $cardId): ?CardSnapshot;

    /** Answers null when the card belongs to another project. */
    public function findInProject(Uuid $projectId, Uuid $cardId): ?CardSnapshot;

    /** Reads the stored column, type and parent again, so the snapshot shows what a move or an edit committed. */
    public function refresh(Uuid $cardId): ?CardSnapshot;

    /** Keeps the column the card has in memory, and reads the stored column of its parent again. */
    public function findWithParentColumn(Uuid $cardId): ?CardSnapshot;

    /** @return list<Uuid> the children of the card in board order, or none for an unknown card */
    public function childIds(Uuid $cardId): array;
}
