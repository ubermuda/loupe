<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

use App\Module\Board\Entity\CardPauseKind;

/** Why a card waits, as a code. The pause cases carry the value of their CardPauseKind. */
enum InboxCardWaitReason: string
{
    case WaitingForReview = 'waiting-for-review';
    case NewCommitsAfterApproval = 'new-commits-after-approval';
    case FixStopped = 'fix-stopped';
    case Blocked = 'blocked';
    case GaveUp = 'gave-up';
    case WaitsForPerson = 'waits-for-person';
    case PauseRule = 'rule';
    case PauseRetries = 'retries';
    case PauseWorkLimit = 'work-limit';
    case PauseWorkTimeout = 'work-timeout';
    case PauseWorkStopped = 'work-stopped';

    public static function forPause(CardPauseKind $kind): self
    {
        return self::from($kind->value);
    }

    /** The translation key part: the code with underscores. */
    public function keyPart(): string
    {
        return str_replace('-', '_', $this->value);
    }
}
