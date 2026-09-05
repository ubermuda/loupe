<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AddCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddCommentCommand $command): SiteReviewComment
    {
        // MAX(position) + 1 is read-then-write: two widget requests would
        // otherwise allocate the same position and leave the ordering unstable.
        // Same PESSIMISTIC_WRITE-on-the-project idiom the token mint handlers use.
        $comment = $this->em->wrapInTransaction(function () use ($command): SiteReviewComment {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $comment = new SiteReviewComment(
                project: $command->project,
                position: $this->siteReviewComments->nextPositionForProject($command->project),
                body: $command->body,
                url: $command->url,
            );
            foreach ($command->anchors as $anchor) {
                $comment->addAnchor(
                    selector: $anchor->selector,
                    text: $anchor->text,
                    quote: $anchor->quote,
                    quotePrefix: $anchor->quotePrefix,
                    quoteSuffix: $anchor->quoteSuffix,
                );
            }
            $this->em->persist($comment);
            $this->em->flush();

            return $comment;
        });

        $this->auditor->record(
            'site_review.comment_added',
            AuditOutcome::Success,
            [
                'projectId' => (string) $command->project->id,
                'commentId' => (string) $comment->id,
            ],
            new AuditSubject('site_review_comment', (string) $comment->id),
        );

        return $comment;
    }
}
