<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UpdateCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateCommentCommand $command): SiteReviewComment
    {
        $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project)
            ?? throw CommentNotFound::forId($command->commentId);

        $comment->body = $command->body;
        $this->em->flush();

        $this->logger->info('site_review.comment.updated', [
            'projectId' => (string) $command->project->id,
            'commentId' => (string) $command->commentId,
        ]);

        return $comment;
    }
}
