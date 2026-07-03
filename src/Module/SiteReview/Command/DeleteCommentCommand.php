<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

final readonly class DeleteCommentCommand
{
    public function __construct(
        public Project $project,
        public Uuid $commentId,
    ) {
    }
}
