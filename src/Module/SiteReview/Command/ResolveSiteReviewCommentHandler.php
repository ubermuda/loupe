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

final readonly class ResolveSiteReviewCommentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(ResolveSiteReviewCommentCommand $command): void
    {
        $command->comment->status = SiteReviewCommentStatus::Resolved;
        $this->em->flush();

        $context = ['commentId' => (string) $command->comment->id];
        if (null !== $command->trigger) {
            $context['trigger'] = $command->trigger;
        }
        if (null !== $command->actor) {
            $context['actor'] = $command->actor;
        }

        $this->auditor->record(
            'site_review.comment_resolved',
            AuditOutcome::Success,
            $context,
            new AuditSubject('site_review_comment', (string) $command->comment->id),
        );

        $this->events->dispatch(new SiteReviewCommentStatusChanged(
            $command->comment->project->id ?? throw new \LogicException('Project has no id.'),
            $command->comment->id ?? throw new \LogicException('Comment has no id.'),
        ));
    }
}
