<?php

declare(strict_types=1);

namespace App\Module\Bridge\Event;

use App\Module\Project\Entity\Project;

/** A heartbeat changed the name a bridge holds. Dispatched after the commit. */
final readonly class BridgeNameChanged
{
    /** @param list<Project> $projects the owner's projects that the bridge follows */
    public function __construct(
        public array $projects,
    ) {
    }
}
