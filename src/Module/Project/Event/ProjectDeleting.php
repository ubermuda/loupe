<?php

declare(strict_types=1);

namespace App\Module\Project\Event;

use App\Module\Project\Entity\Project;

/**
 * Dispatched inside ProjectDeleter's transaction, before the project row is
 * removed. Modules owning data that references the project delete their own
 * rows in a listener — the event class is the module's public API.
 */
final readonly class ProjectDeleting
{
    public function __construct(public Project $project)
    {
    }
}
