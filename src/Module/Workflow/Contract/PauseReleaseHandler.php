<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Ends a workflow pause for a person or an agent. The workflow engine implements it. */
interface PauseReleaseHandler
{
    /**
     * @param ?Uuid $pauseId the pause to end, or null for the active one
     *
     * @throws \App\Exception\DomainErrors when the pause cannot end
     */
    public function release(CardSnapshot $card, Uuid $actorUserId, Actor $actor, ?Uuid $pauseId): PauseView;
}
