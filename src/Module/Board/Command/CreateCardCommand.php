<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardSource;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;

final readonly class CreateCardCommand
{
    /**
     * @param list<string>        $pullRequestUrls raw URLs as given; the handler resolves the forge
     * @param list<string>        $documentIds     documents of this project; the handler refuses any other
     * @param list<CardLinkInput> $relatedCards    cards of this project the new card links to
     */
    public function __construct(
        public Project $project,
        public string $title,
        public string $body,
        public string $type,
        /** Null lands the card in the board's Backlog. */
        public ?BoardColumn $column = null,
        public Actor $reporter = Actor::Agent,
        public array $pullRequestUrls = [],
        /** @param list<string> $documentIds */
        public array $documentIds = [],
        public array $relatedCards = [],
        /** An epic of this project. Null or blank gives the card no parent. */
        public ?string $parentCardId = null,
        /** Null keeps the entity default, which draws the lane. */
        public ?bool $laneEnabled = null,
        /** Who makes the call, for the card's history. Null means the reporter. */
        public ?Actor $actor = null,
        /** Null derives the source from the reporter. */
        public ?CardSource $source = null,
    ) {
    }
}
