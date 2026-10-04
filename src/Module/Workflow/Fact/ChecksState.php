<?php

declare(strict_types=1);

namespace App\Module\Workflow\Fact;

/** The combined result of the required checks on the head commit. */
enum ChecksState: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Pending = 'pending';
    case None = 'none';
}
