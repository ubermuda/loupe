<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;

/**
 * Dispatched inside the transaction of a card write, after its flush, when
 * the cards lost a blocks link with no move: a link removed or turned around,
 * or a blocker deleted.
 */
final readonly class CardBlockersRemoved
{
    /** @param non-empty-list<Card> $cards the cards that lost a blocker */
    public function __construct(
        public Project $project,
        public array $cards,
        public Actor $actor,
    ) {
    }
}
