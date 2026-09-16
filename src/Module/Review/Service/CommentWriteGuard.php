<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Comment;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class CommentWriteGuard
{
    public function __construct(
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function lockAndCheck(Comment $comment, string $field): ?DomainErrors
    {
        if (!$this->comments->lockAndRefreshState($comment)) {
            return new DomainErrors([$field => 'comment.error.not_found']);
        }
        if ($comment->isDeleted) {
            return new DomainErrors([$field => 'comment.error.deleted']);
        }
        if (!$this->documentVersions->findLatest($comment->version->document)->id?->equals($comment->version->id)) {
            return new DomainErrors([$field => 'review.document.comment.error.stale_version']);
        }

        return null;
    }
}
