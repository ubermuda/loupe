<?php

declare(strict_types=1);

namespace App\Module\Forge\Entity;

/**
 * How a project came to hold a repository. Only the forge itself can vouch for
 * an installation, so only an installation row is exclusive. A project owner
 * knows the secret of the project's own hook, so a hook row proves nothing
 * about who owns the repository.
 */
enum ForgeRepositorySource: string
{
    case Hook = 'hook';
    case Installation = 'installation';
}
