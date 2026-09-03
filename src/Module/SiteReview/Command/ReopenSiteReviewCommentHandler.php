<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ReopenSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ReopenSiteReviewCommentCommand $command): void
    {
        $command->comment->status = SiteReviewCommentStatus::Pending;
        $this->em->flush();

        $this->auditor->record(
            'site_review.comment_reopened',
            AuditOutcome::Success,
            ['commentId' => (string) $command->comment->id],
            new AuditSubject('site_review_comment', (string) $command->comment->id),
        );
    }
}
