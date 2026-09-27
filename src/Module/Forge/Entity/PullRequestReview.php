<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

enum PullRequestReview: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';
    case Required = 'required';
    case None = 'none';
}
