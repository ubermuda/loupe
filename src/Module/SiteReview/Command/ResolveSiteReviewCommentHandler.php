<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ResolveSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ResolveSiteReviewCommentCommand $command): void
    {
        $command->comment->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        $this->auditor->record(
            'site_review.comment.resolved',
            AuditOutcome::Success,
            ['commentId' => (string) $command->comment->id],
            new AuditSubject('site_review_comment', (string) $command->comment->id),
        );
    }
}
