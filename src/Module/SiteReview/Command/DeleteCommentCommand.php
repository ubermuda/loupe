<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\Site;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteCommentCommand
{
    public function __construct(
        public Site $site,
        public Uuid $commentId,
    ) {
    }
}
