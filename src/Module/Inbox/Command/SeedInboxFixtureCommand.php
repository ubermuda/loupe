<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;

final readonly class SeedInboxFixtureCommand
{
    public function __construct(
        public Project $project,
        /** A card of the project the question links to, so its card page lists it. */
        public ?string $cardId = null,
        /** A document of the project the question links to, so its document page lists it. */
        public ?string $documentId = null,
    ) {
    }
}
