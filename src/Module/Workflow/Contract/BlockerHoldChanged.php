<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Dispatched after the evaluation that started or ended a blocker hold commits, so the tile of the card shows it. */
final readonly class BlockerHoldChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
    ) {
    }
}
