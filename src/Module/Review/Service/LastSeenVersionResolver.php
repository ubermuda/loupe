<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\ValueObject\Engagement;

/**
 * Which version of a document a reader last engaged with, derived from their own
 * comments and verdicts. Nothing records a read, so engagement is the signal —
 * and it is the reader's own, which is why an agent reply on their behalf counts
 * as the agent account's engagement rather than theirs.
 *
 * The version comes off the engagement rows themselves. Resolving a timestamp
 * against version creation times cannot work: every column involved is
 * TIMESTAMP(0), so a revision written in the same second as a comment would
 * count as already seen and its changes would never be offered.
 */
final readonly class LastSeenVersionResolver
{
    public function __construct(
        private CommentRepository $comments,
        private ReviewRepository $reviews,
    ) {
    }

    /** Null when the reader is anonymous, or has never engaged with the document. */
    public function versionNumberFor(Document $document, ?User $reader): ?int
    {
        if (null === $reader) {
            return null;
        }

        return $this->later(
            $this->comments->findLatestEngagementByDocumentAndAuthor($document, $reader),
            $this->reviews->findLatestEngagementByDocumentAndReviewer($document, $reader),
        );
    }

    /** On an exact tie, the higher version: both events are the reader's own, so they saw both. */
    private function later(?Engagement $comment, ?Engagement $verdict): ?int
    {
        if (null === $comment) {
            return $verdict?->versionNumber;
        }

        if (null === $verdict) {
            return $comment->versionNumber;
        }

        return match (true) {
            $comment->at > $verdict->at => $comment->versionNumber,
            $verdict->at > $comment->at => $verdict->versionNumber,
            default => max($comment->versionNumber, $verdict->versionNumber),
        };
    }
}
