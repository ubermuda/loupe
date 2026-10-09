<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Removes the engine rows that point at a card or a column which Board has deleted. The engine implements it. */
interface WorkflowRowCleanup
{
    /** Deletes the rule states and the pending baseline of the card. It takes no lock, so it can run inside the transaction that deletes the card. */
    public function forgetCard(Uuid $cardId): void;

    /** Deletes them again after the card delete has committed, once an evaluation of the card that was in flight has ended. */
    public function sweepCard(Uuid $cardId): void;

    /** Clears the column of every slot link that held it, so the slot shows as unlinked. */
    public function forgetColumn(Uuid $columnId): void;
}
