<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DocumentStatus;
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

    public function __invoke(UndoVerdictCommand $command): void
    {
        $document = $command->document;

        $this->em->wrapInTransaction(function () use ($document): void {
            // Same lock SubmitReviewHandler takes, for the same reason: a revision
            // landing between the read and the write would otherwise undo a verdict
            // attached to a version nobody is looking at any more.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            $version = $this->documentVersions->findLatest($document);
            $verdicts = $this->reviews->findByVersionNewestFirst($version);
            $latest = $verdicts[0]
                ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_none']);

            // The row goes, rather than the status alone: document_get_review reports
            // the verdict from the latest Review on the version, so a status-only undo
            // would leave the agent reading an approval the page no longer shows.
            $this->em->remove($latest);

            // Undo pops one verdict rather than clearing them all, so a version that
            // somehow carries two falls back to the one underneath.
            $previous = $verdicts[1] ?? null;
            $document->status = match ($previous?->verdict) {
                Verdict::Approved => DocumentStatus::Approved,
                Verdict::ChangesRequested => DocumentStatus::ChangesRequested,
                null => DocumentStatus::InReview,
            };

            $this->em->flush();
        });
    }
}
