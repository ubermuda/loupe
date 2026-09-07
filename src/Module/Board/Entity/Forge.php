<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/**
 * The code-hosting service a pull request link points at. Other covers every
 * host no parser recognises, including a self-hosted one.
 */
enum Forge: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Bitbucket = 'bitbucket';
    case Other = 'other';
}
