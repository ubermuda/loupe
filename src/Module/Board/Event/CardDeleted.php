<?php

declare(strict_types=1);

namespace App\Module\Board\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Dispatched inside DeleteCardHandler's transaction, after the flush that removed
 * the card. It carries ids, because that flush clears the removed entity's id.
 * A listener must never throw, for the reason CardMoved gives.
 */
final readonly class CardDeleted
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
    ) {
    }
}
