<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\Site;

final readonly class AddCommentCommand
{
    public function __construct(
        public Site $site,
        /** @phpstan-var non-empty-string */
        public string $body,
        public string $selector,
        public string $text,
        public string $url,
    ) {
    }
}
