<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class DeleteCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteCommentCommand $command): void
    {
        $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project)
            ?? throw CommentNotFound::forId($command->commentId);

        $this->em->remove($comment);
        $this->em->flush();

        $this->auditor->record(
            'site_review.comment_deleted',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'commentId' => (string) $command->commentId,
            ],
            new AuditSubject('site_review_comment', (string) $command->commentId),
        );
    }
}
