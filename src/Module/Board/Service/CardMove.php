<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;

/**
 * Where a move started from. The card itself carries where it arrived.
 *
 * auditContext() shapes the transition for the trail, and the CardMoved event
 * hands the same object to a listener that needs the other end of it.
 */
final readonly class CardMove
{
    public function __construct(
        public BoardColumn $fromColumn,
        public CardPriority $fromPriority,
    ) {
    }

    /**
     * The status keys hold slugs, which is what a record written before
     * columns were rows holds too, so old and new records share one shape.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(Card $card): array
    {
        return [
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $card->project->id,
            'fromStatus' => $this->fromColumn->slug,
            'fromColumnId' => (string) $this->fromColumn->id,
            'fromPriority' => $this->fromPriority->value,
            'toStatus' => $card->column->slug,
            'toColumnId' => (string) $card->column->id,
            'toPriority' => $card->priority->value,
            'position' => $card->position,
        ];
    }
}
