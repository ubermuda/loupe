<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** What the workflow knows about the state of cards that Board shows on a tile. The engine implements it. */
interface CardStateHints
{
    /** Null when the project has no template. */
    public function stageDocumentTags(Uuid $projectId): ?StageDocumentTags;

    /**
     * The cards that a move rule holds for an open blocker alone, each with its open blocker of the lowest number.
     *
     * @param list<string> $cardIds RFC 4122 ids
     *
     * @return list<BlockerHold>
     */
    public function blockerHolds(array $cardIds): array;
}
