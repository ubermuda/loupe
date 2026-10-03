<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

/** A work request opened, was claimed, settled, withdrew or opened again. Dispatched after the commit, with ids only. */
final readonly class WorkRequestChanged
{
    public function __construct(
        public Uuid $projectId,
        public Uuid $cardId,
        public Uuid $workRequestId,
        public WorkRequestState $state,
    ) {
    }
}
