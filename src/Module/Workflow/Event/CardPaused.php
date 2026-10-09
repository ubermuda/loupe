<?php

declare(strict_types=1);

namespace App\Module\Workflow\Event;

use App\Module\Workflow\Contract\PauseKind;
use Symfony\Component\Uid\Uuid;

/** Dispatched after the evaluation that paused the card commits. */
final readonly class CardPaused
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
        public string $reason,
        public PauseKind $kind,
    ) {
    }
}
