<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use App\Module\Review\Repository\DocumentVersionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): Review
    {
        $verdict = Verdict::tryFrom($command->verdict)
            ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_invalid']);

        $document = $command->document;

        return $this->em->wrapInTransaction(function () use ($command, $document, $verdict): Review {
            // The version is read under the document's own row lock, as
            // SelectDecisionOptionHandler does: a revision landing between the
            // read and the write would otherwise attach the verdict to a
            // version the reviewer never saw.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            $review = new Review(
                version: $this->documentVersions->findLatest($document),
                verdict: $verdict,
                reviewer: $command->reviewer,
            );

            $document->status = match ($verdict) {
                Verdict::Approved => DocumentStatus::Approved,
                Verdict::ChangesRequested => DocumentStatus::ChangesRequested,
            };

            $this->em->persist($review);
            $this->em->flush();

            return $review;
        });
    }
}
