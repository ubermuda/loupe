<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxReviewVerdict: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';
}
