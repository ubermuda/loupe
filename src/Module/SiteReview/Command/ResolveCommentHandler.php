<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Resolves a comment on behalf of the reviewer who wrote it, from the widget.
 *
 * The same status the project owner sets in the web UI, reached with a widget
 * token instead of a session. Pending only, like editing and deleting: a
 * comment the agent has addressed belongs to the owner's sign-off.
 */
final readonly class ResolveCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ResolveCommentCommand $command): void
    {
        $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project)
            ?? throw CommentNotFound::forId($command->commentId);

        $comment->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        $this->auditor->record(
            'site_review.comment_resolved',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'commentId' => (string) $command->commentId,
                'via' => 'widget',
            ],
            new AuditSubject('site_review_comment', (string) $command->commentId),
        );
    }
}
