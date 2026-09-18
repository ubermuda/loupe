<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Exception\DomainErrors;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Event\SiteReviewCommentCreated;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class AddCommentHandler
{
    public function __construct(
        private SiteReviewCommentRepository $siteReviewComments,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(AddCommentCommand $command): SiteReviewComment
    {
        if (null !== $command->deliveryId && !Uuid::isValid($command->deliveryId)) {
            throw new DomainErrors(['deliveryId' => 'delivery_invalid']);
        }
        $deliveryId = null === $command->deliveryId ? null : Uuid::fromString($command->deliveryId);
        $deliveryHash = null === $deliveryId ? null : hash('sha256', json_encode([
            $command->body, $command->url, $command->anchors, $command->strokes, $command->context,
        ], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        $created = false;
        // MAX(position) + 1 is read-then-write: two widget requests would
        // otherwise allocate the same position and leave the ordering unstable.
        // Same PESSIMISTIC_WRITE-on-the-project idiom the token mint handlers use.
        $comment = $this->em->wrapInTransaction(function () use ($command, $deliveryId, $deliveryHash, &$created): SiteReviewComment|DomainErrors {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            if (null !== $deliveryId) {
                $existing = $this->siteReviewComments->findOneBy(['project' => $command->project, 'deliveryId' => $deliveryId]);
                if (null !== $existing) {
                    return $existing->deliveryHash === $deliveryHash
                        ? $existing
                        : new DomainErrors(['deliveryId' => 'delivery_conflict']);
                }
            }

            $comment = new SiteReviewComment(
                project: $command->project,
                position: $this->siteReviewComments->nextPositionForProject($command->project),
                body: $command->body,
                url: $command->url,
                context: $command->context,
                deliveryId: $deliveryId,
                deliveryHash: $deliveryHash,
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
            // Null rather than an empty list, so "drew nothing" reads the same
            // way for a comment saved before drawing shipped.
            $comment->strokes = [] === $command->strokes ? null : array_map(
                static fn (NewStroke $stroke): array => ['space' => $stroke->space, 'points' => $stroke->points],
                $command->strokes,
            );
            $this->em->persist($comment);
            $this->em->flush();
            $this->events->dispatch(new SiteReviewCommentCreated($comment));
            $created = true;

            return $comment;
        });

        if ($comment instanceof DomainErrors) {
            throw $comment;
        }
        if (!$created) {
            return $comment;
        }

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
