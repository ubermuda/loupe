<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A group of facts that the engine itself builds, as opposed to the facts of a provider. */
enum EngineFact: string
{
    case Slot = 'slot';
    case ParentSlot = 'parent-slot';
    case PullRequest = 'pull-request';
}
