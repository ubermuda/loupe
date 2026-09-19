<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\ValueObject\DocumentVersionHistoryEntry;

final readonly class ShowDocumentHistoryHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
        private CommentRepository $comments,
    ) {
    }

    public function __invoke(ShowDocumentHistoryCommand $command): ShowDocumentHistoryView
    {
        $reviewsByVersion = [];
        foreach ($this->reviews->findHistoryByDocument($command->document) as $review) {
            $reviewsByVersion[$review->version->versionNumber][] = $review;
        }

        $rows = $this->documentVersions->findAllMetaByDocument($command->document);
        $signals = $this->comments->signalsByVersions(array_column($rows, 'id'));

        $versions = [];
        foreach ($rows as $meta) {
            $versions[] = new DocumentVersionHistoryEntry(
                versionNumber: $meta['versionNumber'],
                createdAt: $meta['createdAt'],
                description: $meta['description'],
                reviews: $reviewsByVersion[$meta['versionNumber']] ?? [],
                threadCount: $signals[$meta['id']]?->threadCount() ?? 0,
            );
        }

        return new ShowDocumentHistoryView(
            document: $command->document,
            versions: $versions,
        );
    }
}
