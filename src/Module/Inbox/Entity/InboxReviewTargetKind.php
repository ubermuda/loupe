<?php

declare(strict_types=1);

namespace App\Module\Inbox\Entity;

enum InboxReviewTargetKind: string
{
    case Document = 'document';
    case PullRequest = 'pull-request';
}
