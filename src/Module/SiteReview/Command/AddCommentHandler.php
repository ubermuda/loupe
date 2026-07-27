<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
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
        $position = $this->siteReviewComments->nextPositionForProject($command->project);
        $comment = new SiteReviewComment(
            project: $command->project,
            position: $position,
            body: $command->body,
            selector: $command->selector,
            text: $command->text,
            url: $command->url,
        );
        $this->em->persist($comment);
        $this->em->flush();

        $this->logger->info('site_review.comment.added', [
            'projectId' => (string) $command->project->id,
            'commentId' => (string) $comment->id,
        ]);

        return $comment;
    }
}
