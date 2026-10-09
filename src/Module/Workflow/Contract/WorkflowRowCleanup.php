<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Removes the engine rows that point at a card or a column which Board has deleted. The engine implements it. */
interface WorkflowRowCleanup
{
    /** Deletes the rule states and the pending baseline of the card. */
    public function forgetCard(Uuid $cardId): void;

    /** Clears the column of every slot link that held it, so the slot shows as unlinked. */
    public function forgetColumn(Uuid $columnId): void;
}
