<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The shape of the name of a kind of work, such as design, implement, fix or merge. */
final class WorkKind
{
    public const string PATTERN = '/^[a-z][a-z0-9-]{0,39}$/D';
}
