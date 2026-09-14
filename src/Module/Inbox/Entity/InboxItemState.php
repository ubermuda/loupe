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
}
