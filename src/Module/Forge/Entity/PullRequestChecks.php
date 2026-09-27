<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

/** The combined result of the checks on the head commit. */
enum PullRequestChecks: string
{
    case Pending = 'pending';
    case Passed = 'passed';
    case Failed = 'failed';
}
