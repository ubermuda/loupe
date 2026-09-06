<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardOrigin;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;

final readonly class CreateCardCommand
{
    /** @param list<string> $pullRequestUrls raw URLs as given; the handler resolves the forge */
    public function __construct(
        public Project $project,
        public string $title,
        public string $body,
        public CardType $type,
        public CardPriority $priority,
        public CardStatus $status = CardStatus::Backlog,
        public CardOrigin $origin = CardOrigin::Agent,
        public array $pullRequestUrls = [],
    ) {
    }
}
