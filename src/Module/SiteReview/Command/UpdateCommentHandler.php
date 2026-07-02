<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(UpdateCommentCommand $command): SiteReviewComment
    {
        $comment = $this->siteReviewComments->findOneInDraftReview($command->commentId, $command->site)
            ?? throw CommentNotFound::forId($command->commentId);

        $comment->body = $command->body;
        $this->em->flush();

        return $comment;
    }
}
