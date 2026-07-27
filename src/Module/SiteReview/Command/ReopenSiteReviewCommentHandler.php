<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ReopenSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReopenSiteReviewCommentCommand $command): void
    {
        if (SiteReviewCommentStatus::Draft === $command->comment->status) {
            throw new DomainErrors(['comment' => 'site_review.error.comment_not_actionable']);
        }

        $command->comment->status = SiteReviewCommentStatus::Pending;
        $this->em->flush();

        $this->logger->info('site_review.comment.reopened', [
            'commentId' => (string) $command->comment->id,
        ]);
    }
}
