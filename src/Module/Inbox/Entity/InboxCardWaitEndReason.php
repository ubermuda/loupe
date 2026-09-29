<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** Resolved ends a wait by its own cause, such as a verdict or a newer run. */
enum InboxCardWaitEndReason: string
{
    case Resolved = 'resolved';
    case CardFinished = 'card-finished';
    case CardDeleted = 'card-deleted';
    case SwitchedOff = 'switched-off';
    case Dismissed = 'dismissed';
}
