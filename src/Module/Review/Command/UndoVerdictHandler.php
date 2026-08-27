<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DocumentStatus;
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
            $verdict = $this->reviews->findByVersion($version)
                ?? throw new DomainErrors(['verdict' => 'review.document.flash.verdict_none']);

            // The row goes, rather than the status alone: document_get_review reports
            // the verdict from the Review on the version, so a status-only undo would
            // leave the agent reading an approval the page no longer shows.
            $this->em->remove($verdict);
            $document->status = DocumentStatus::InReview;

            $this->em->flush();
        });
    }
}
