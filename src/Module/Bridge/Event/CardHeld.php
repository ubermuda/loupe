<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use Symfony\Component\Uid\Uuid;

/** A card got a hold, so it is unmanaged now. Dispatched inside the transaction of the hold. */
final readonly class CardHeld
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
    ) {
    }
}
