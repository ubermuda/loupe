<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

/** Unknown means the forge has not computed the answer yet, so a later read can still give one. */
enum PullRequestMergeability: string
{
    case Mergeable = 'mergeable';
    case Conflicting = 'conflicting';
    case Behind = 'behind';
    case Blocked = 'blocked';
    case Unknown = 'unknown';
}
