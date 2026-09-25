<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Command\NewAnchor;
use App\Module\SiteReview\Command\NewStroke;

final readonly class AddFeedbackCommand
{
    /**
     * @param list<NewAnchor> $anchors an empty list is an unanchored page note
     * @param list<NewStroke> $strokes freehand drawing over the page, if any
     */
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $body,
        public string $url,
        public array $anchors = [],
        public array $strokes = [],
        public ?string $deliveryId = null,
        /** An open card of the project. Null creates a card from the note. */
        public ?string $cardId = null,
        /** The epic a created card goes under. Read only when $cardId is null. */
        public ?string $parentCardId = null,
    ) {
    }
}
