<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;

/** Board may not import Workflow, so the workflow engine decides which column moves a managed card allows. */
interface CardMoveGuard
{
    /** Asked under the project lock, with the card's column fresh, for a move to another column. */
    public function allows(Card $card, BoardColumn $to, CardReporter $actor, ?CardEventCause $cause): bool;
}
