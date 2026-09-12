<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;

final readonly class CreateCardCommand
{
    /**
     * @param list<string> $pullRequestUrls raw URLs as given; the handler resolves the forge
     * @param list<string> $documentIds     documents of this project; the handler refuses any other
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $body,
        public CardType $type,
        public CardPriority $priority,
        public CardStatus $status = CardStatus::Backlog,
        public CardReporter $reporter = CardReporter::Agent,
        public array $pullRequestUrls = [],
        /** @param list<string> $documentIds */
        public array $documentIds = [],
    ) {
    }
}
