<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** How the CLI was installed, as its last heartbeat reported it. A binary from the install script sends no method. */
enum CliInstallMethod: string
{
    case Homebrew = 'homebrew';
}
