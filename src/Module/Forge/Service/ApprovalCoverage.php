<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

/** Whether the approval of a pull request still covers its head. */
enum ApprovalCoverage
{
    case Covered;
    case NotCovered;
    case Unknown;
}
