<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads and writes the pauses of cards for the workflow. Board implements it. */
interface CardPauses
{
    public function findActive(Uuid $cardId): ?PauseView;

    /** The newest pause of the card, released or not. */
    public function findLatest(Uuid $cardId): ?PauseView;

    /** Answers null when the card already holds an active pause. */
    public function pause(CardSnapshot $card, string $reason, string $ruleId, PauseKind $kind): ?PauseView;

    /** Answers null when the pause was already released. */
    public function release(PauseView $pause, string $reason): ?PauseView;

    /** Writes the history row of a released pause. */
    public function recordReleased(PauseView $pause, Actor $actor, ?Uuid $actorUserId): void;
}
