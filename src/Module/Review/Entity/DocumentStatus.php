<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

enum DocumentStatus: string
{
    case InReview = 'in-review';
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';
}
