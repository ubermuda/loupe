<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UpdateCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateCommentCommand $command): SiteReviewComment
    {
        $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project)
            ?? throw CommentNotFound::forId($command->commentId);

        $comment->body = $command->body;
        $this->em->flush();

        $this->auditor->record(
            'site_review.comment_updated',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'commentId' => (string) $command->commentId,
            ],
            new AuditSubject('site_review_comment', (string) $command->commentId),
        );

        return $comment;
    }
}
