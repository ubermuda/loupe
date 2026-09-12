<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;

/**
 * Where a move started from. The card itself carries where it arrived.
 *
 * auditContext() shapes the transition for the trail, and the CardMoved event
 * hands the same object to a listener that needs the other end of it.
 */
final readonly class CardMove
{
    public function __construct(
        public CardStatus $fromStatus,
        public CardPriority $fromPriority,
    ) {
    }

    /** @return array<string, scalar|null> */
    public function auditContext(Card $card): array
    {
        return [
            'cardId' => (string) $card->id,
            'cardNumber' => $card->number,
            'projectId' => (string) $card->project->id,
            'fromStatus' => $this->fromStatus->value,
            'fromPriority' => $this->fromPriority->value,
            'toStatus' => $card->status->value,
            'toPriority' => $card->priority->value,
            'position' => $card->position,
        ];
    }
}
