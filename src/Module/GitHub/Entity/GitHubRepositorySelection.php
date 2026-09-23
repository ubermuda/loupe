<?php

declare(strict_types=1);

namespace App\Module\GitHub\Entity;

/** Which repositories an App installation can reach, as GitHub spells it. */
enum GitHubRepositorySelection: string
{
    case All = 'all';
    case Selected = 'selected';
}
