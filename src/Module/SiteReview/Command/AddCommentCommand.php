<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;

final readonly class AddCommentCommand
{
    public function __construct(
        public Project $project,
        /** @phpstan-var non-empty-string */
        public string $body,
        public string $selector,
        public string $text,
        public string $url,
    ) {
    }
}
