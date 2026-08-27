<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UndoVerdictHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
    ) {
    }

    public function __invoke(UndoVerdictCommand $command): Review
    {
        $document = $command->document;

        return $this->em->wrapInTransaction(function () use ($command, $document): Review {
            // Same lock SubmitReviewHandler takes, and needed for the same two
            // reasons: a revision must not land between reading the version and
            // writing to it, and the next sequence number must be read and used
            // without another append slipping in between.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($document);
            $newest = $this->reviews->findNewestByVersion($version)
                ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_none']);

            if (Verdict::Withdrawn === $newest->verdict) {
                throw new DomainErrors(['verdict' => 'review.document.flash.verdict_already_withdrawn']);
            }

            // A withdrawal is appended, never a deletion or an edit of the verdict it
            // takes back: the log is what says the document was approved at one point
            // and by whom, and both of those are answers a reader wants later.
            $withdrawal = new Review(
                version: $version,
                verdict: Verdict::Withdrawn,
                reviewer: $command->actor,
                sequence: $newest->sequence + 1,
            );

            $document->status = Verdict::Withdrawn->documentStatus();

            $this->em->persist($withdrawal);
            $this->em->flush();

            return $withdrawal;
        });
    }
}
