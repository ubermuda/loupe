<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class AskInboxCommand
{
    /** @param list<AskInboxItem> $items */
    public function __construct(
        public Project $project,
        public Uuid $sessionId,
        public array $items,
        /** Null when an interactive session asks, which no bridge can resume. */
        public ?Uuid $bridgeId = null,
        public ?string $context = null,
    ) {
    }
}
