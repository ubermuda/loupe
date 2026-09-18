<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Repository\ReviewRepository;
use App\Module\Review\ValueObject\DocumentVersionHistoryEntry;

final readonly class ShowDocumentHistoryHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
        private ReviewRepository $reviews,
    ) {
    }

    public function __invoke(ShowDocumentHistoryCommand $command): ShowDocumentHistoryView
    {
        $reviewsByVersion = [];
        foreach ($this->reviews->findHistoryByDocument($command->document) as $review) {
            $reviewsByVersion[$review->version->versionNumber][] = $review;
        }

        $versions = [];
        foreach ($this->documentVersions->findAllMetaByDocument($command->document) as $meta) {
            $versions[] = new DocumentVersionHistoryEntry(
                versionNumber: $meta['versionNumber'],
                createdAt: $meta['createdAt'],
                description: $meta['description'],
                reviews: $reviewsByVersion[$meta['versionNumber']] ?? [],
            );
        }

        return new ShowDocumentHistoryView(
            document: $command->document,
            versions: $versions,
        );
    }
}
