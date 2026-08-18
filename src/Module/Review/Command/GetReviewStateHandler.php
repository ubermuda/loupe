<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class GetReviewStateHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(GetReviewStateCommand $command): GetReviewStateView
    {
        // Owner-scoped for the e2e harness user.
        $document = $this->documents->findOneBy(['id' => $command->documentId, 'owner' => $command->owner]);
        if (null === $document) {
            return new GetReviewStateView(null, []);
        }

        return new GetReviewStateView($document, $this->storedAnchors($document));
    }

    /** @return list<array{quote: string, prefix: string, suffix: string}> */
    private function storedAnchors(Document $document): array
    {
        $anchors = [];
        foreach ($this->comments->findByVersion($this->documentVersions->findLatest($document)) as $comment) {
            $anchors[] = [
                'quote' => $comment->anchor->quote,
                'prefix' => $comment->anchor->prefix,
                'suffix' => $comment->anchor->suffix,
            ];
        }

        return $anchors;
    }
}
