<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

/** Every state except Open is closed. */
enum InboxItemState: string
{
    case Open = 'open';
    case Answered = 'answered';
    case Done = 'done';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
    case Obsolete = 'obsolete';

    /** An owner response stays open to change until a closed ask holds the item. An agent's close is never the owner's to change. */
    public function acceptsResponse(bool $heldByClosedAsk): bool
    {
        return match ($this) {
            self::Open => true,
            self::Withdrawn, self::Obsolete => false,
            self::Answered, self::Done, self::Declined => !$heldByClosedAsk,
        };
    }
}
