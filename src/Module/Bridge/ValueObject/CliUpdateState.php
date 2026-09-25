<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Where the CLI's own update stands, as its last heartbeat reported it. The values arrive on the wire. */
enum CliUpdateState: string
{
    case Current = 'current';
    case Updating = 'updating';
    case RolledBack = 'rolled-back';
    case Blocked = 'blocked';
    case Off = 'off';
    case Dev = 'dev';
}
