<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

enum PullRequestState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
}
