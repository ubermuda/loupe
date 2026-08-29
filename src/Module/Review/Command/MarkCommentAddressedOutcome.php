<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

/** What became of one comment in a mark-as-addressed batch. */
enum MarkCommentAddressedOutcome
{
    case Addressed;
    case Superseded;
    case IsReply;
    case AlreadyAddressed;
    case AlreadyResolved;
    case NotFound;
}
