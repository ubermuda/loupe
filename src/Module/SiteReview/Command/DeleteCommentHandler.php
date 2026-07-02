<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        $comment = $this->siteReviewComments->findOneInDraftReview($command->commentId, $command->site)
            ?? throw CommentNotFound::forId($command->commentId);

        $this->em->remove($comment);
        $this->em->flush();
    }
}
