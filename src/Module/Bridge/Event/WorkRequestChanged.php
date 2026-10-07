<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use Symfony\Component\Uid\Uuid;

/** A work request opened, was claimed, settled, withdrew or opened again. Dispatched after the commit, with ids only. */
final readonly class WorkRequestChanged
{
    public function __construct(
        public Uuid $projectId,
        public string $subjectType,
        public Uuid $subjectId,
        public Uuid $workRequestId,
        public WorkRequestState $state,
    ) {
    }

    /** The card the request is about, or null when its subject is no card. */
    public function cardId(): ?Uuid
    {
        return WorkSubject::CARD === $this->subjectType ? $this->subjectId : null;
    }
}
