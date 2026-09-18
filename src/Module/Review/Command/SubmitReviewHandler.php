<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Event\ReviewSubmitted;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): Review
    {
        $verdict = Verdict::tryFrom($command->verdict)
            ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);

        // Withdrawn is a verdict value but not a submittable one — it is written by
        // UndoVerdictHandler, which checks there is something to withdraw first.
        // Nothing else rejects it: the form DTO only guards against a blank value,
        // so a hand-crafted POST would otherwise reach the log through this route.
        if (Verdict::Withdrawn === $verdict) {
            throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);
        }

        $note = trim($command->note ?? '');
        if (Verdict::ChangesRequested === $verdict && '' === $note) {
            throw new DomainErrors(['note' => 'review.document.flash.note_required']);
        }

        $document = $command->document;

        $result = $this->em->wrapInTransaction(function () use ($command, $document, $verdict, $note): Review|DomainErrors {
            $this->em->lock($document->project, LockMode::PESSIMISTIC_WRITE);
            // The version is read under the document's own row lock, as
            // SelectDecisionOptionHandler does: a revision landing between the
            // read and the write would otherwise attach the verdict to a
            // version the reviewer never saw.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($document);
            if ($version->versionNumber !== $command->versionNumber) {
                return new DomainErrors(['versionNumber' => 'review.document.flash.verdict_stale']);
            }

            $newest = $this->reviews->findNewestByVersion($version);
            $expectedReviewId = null === $command->expectedReviewId ? null : strtolower($command->expectedReviewId);
            if ($newest?->id?->toRfc4122() !== $expectedReviewId) {
                return new DomainErrors(['expectedReviewId' => 'review.document.flash.verdict_changed']);
            }

            // Appended rather than replacing whatever stands: a version may be
            // approved, withdrawn and approved again, and the log keeps all three.
            $review = new Review(
                version: $version,
                verdict: $verdict,
                reviewer: $command->reviewer,
                sequence: null === $newest ? 1 : $newest->sequence + 1,
                note: '' === $note ? null : $note,
            );

            $document->status = $verdict->documentStatus();

            $this->em->persist($review);
            $this->events->dispatch(new ReviewSubmitted($review));
            $this->em->flush();

            return $review;
        });

        if ($result instanceof DomainErrors) {
            throw $result;
        }

        return $result;
    }
}
