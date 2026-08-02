<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

enum CommentStatus: string
{
    case Pending = 'pending';
    case Addressed = 'addressed';
    case Resolved = 'resolved';
}
