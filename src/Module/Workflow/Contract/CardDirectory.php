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
}
