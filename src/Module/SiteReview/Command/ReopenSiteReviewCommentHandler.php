<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewCommentStatus;
use App\Module\SiteReview\Event\SiteReviewCommentStatusChanged;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ReopenSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
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

        $this->events->dispatch(new SiteReviewCommentStatusChanged(
            $command->comment->project->id ?? throw new \LogicException('Project has no id.'),
            $command->comment->id ?? throw new \LogicException('Comment has no id.'),
        ));
    }
}
