<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

enum PullRequestState: string
{
    case Open = 'open';
    case Merged = 'merged';
    case Closed = 'closed';
}
