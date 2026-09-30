<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

enum PullRequestCommentState: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Failed = 'failed';
}
