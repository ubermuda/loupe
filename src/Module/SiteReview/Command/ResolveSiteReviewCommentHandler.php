<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ResolveSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResolveSiteReviewCommentCommand $command): void
    {
        $command->comment->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        $this->logger->info('site_review.comment.resolved', [
            'commentId' => (string) $command->comment->id,
        ]);
    }
}
