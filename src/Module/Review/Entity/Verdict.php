<?php

declare(strict_types=1);

namespace App\Module\Review\Entity;

enum Verdict: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes-requested';
}
