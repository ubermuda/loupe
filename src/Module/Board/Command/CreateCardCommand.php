<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;

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
        public CardType $type,
        /** Null lands the card in the board's default column. */
        public ?BoardColumn $column = null,
        public CardReporter $reporter = CardReporter::Agent,
        public array $pullRequestUrls = [],
        /** @param list<string> $documentIds */
        public array $documentIds = [],
        public ?SiteReviewComment $siteReviewComment = null,
        public array $relatedCards = [],
    ) {
    }
}
