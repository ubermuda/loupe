<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AddCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddCommentCommand $command): SiteReviewComment
    {
        // MAX(position) + 1 is read-then-write: two widget requests would
        // otherwise allocate the same position and leave the ordering unstable.
        // Same PESSIMISTIC_WRITE-on-the-project idiom the token mint handlers use.
        $comment = $this->em->wrapInTransaction(function () use ($command): SiteReviewComment {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $comment = new SiteReviewComment(
                project: $command->project,
                position: $this->siteReviewComments->nextPositionForProject($command->project),
                body: $command->body,
                selector: $command->selector,
                text: $command->text,
                url: $command->url,
            );
            $this->em->persist($comment);
            $this->em->flush();

            return $comment;
        });

        $this->logger->info('site_review.comment.added', [
            'projectId' => (string) $command->project->id,
            'commentId' => (string) $comment->id,
        ]);

        return $comment;
    }
}
