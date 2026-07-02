<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        $comment = $this->siteReviewComments->findOneInDraftReview($command->commentId, $command->project)
            ?? throw CommentNotFound::forId($command->commentId);

        $this->em->remove($comment);
        $this->em->flush();

        $this->logger->info('site_review.comment.deleted', [
            'projectId' => (string) $command->project->id,
            'commentId' => (string) $command->commentId,
        ]);
    }
}
