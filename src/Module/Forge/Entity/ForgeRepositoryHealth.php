<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

enum ForgeRepositoryHealth: string
{
    /** No delivery was accepted yet. */
    case Waiting = 'waiting';

    /** The last accepted delivery is more than 30 days old. */
    case Quiet = 'quiet';

    case Working = 'working';
}
