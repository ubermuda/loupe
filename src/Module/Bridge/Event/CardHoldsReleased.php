<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/** The holds of these cards went. Dispatched inside the caller's transaction, with the ids of the holds that went only. */
final readonly class CardHoldsReleased
{
    /** @param non-empty-list<Uuid> $cardIds */
    public function __construct(
        public Uuid $projectId,
        public array $cardIds,
    ) {
    }
}
