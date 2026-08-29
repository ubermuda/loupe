<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;

/**
 * Which version of a document a reader last engaged with, derived from their own
 * comments and verdicts. Nothing records a read, so engagement is the signal —
 * and it is the reader's own, which is why an agent reply on their behalf moves
 * the agent account's watermark rather than theirs.
 */
final readonly class LastSeenVersionResolver
{
    public function __construct(
        private CommentRepository $comments,
        private ReviewRepository $reviews,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /** Null when the reader is anonymous, or has never engaged with the document. */
    public function versionNumberFor(Document $document, ?User $reader): ?int
    {
        if (null === $reader) {
            return null;
        }

        $watermark = $this->latestOf(
            $this->comments->findLatestCreatedAtByDocumentAndAuthor($document, $reader),
            $this->reviews->findLatestSubmittedAtByDocumentAndReviewer($document, $reader),
        );

        return null === $watermark
            ? null
            : $this->documentVersions->findLatestNumberCreatedAtOrBefore($document, $watermark);
    }

    private function latestOf(?\DateTimeImmutable $first, ?\DateTimeImmutable $second): ?\DateTimeImmutable
    {
        if (null === $first) {
            return $second;
        }

        return null === $second || $first > $second ? $first : $second;
    }
}
