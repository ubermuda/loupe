<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

enum PullRequestReviewKind: string
{
    case Approve = 'approve';
    case RequestChanges = 'request-changes';
    case Comment = 'comment';
}
