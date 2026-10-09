<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Board asks this port before it moves a card, and the workflow engine decides which column moves a managed card allows. */
interface CardMoveGuard
{
    /** Asked under the project lock, with the card's column fresh, for a move to another column. */
    public function allows(CardSnapshot $card, ColumnRef $to, Actor $actor, ?CardEventCause $cause): bool;
}
