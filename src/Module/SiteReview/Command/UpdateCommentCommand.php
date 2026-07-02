<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\Site;
use Symfony\Component\Uid\Uuid;

final readonly class UpdateCommentCommand
{
    public function __construct(
        public Site $site,
        public Uuid $commentId,
        /** @phpstan-var non-empty-string */
        public string $body,
    ) {
    }
}
