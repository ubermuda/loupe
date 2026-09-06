<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class AddCommentCommand
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
    ) {
    }
}
