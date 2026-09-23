<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

enum GitHubHookHealth: string
{
    /** No delivery reached the hook yet. */
    case Waiting = 'waiting';

    /** The last delivery failed verification. */
    case Failing = 'failing';

    case Working = 'working';
}
