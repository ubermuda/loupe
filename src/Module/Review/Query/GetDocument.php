<?php

declare(strict_types=1);

namespace App\Module\Review\Query;

use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class GetDocument
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /**
     * Returns the data of an already-authorized document.
     *
     * @return array{documentId: string, title: string, status: string, version: int, markdown: string}
     */
    public function __invoke(Document $document): array
    {
        $currentVersion = $this->documentVersions->findLatest($document);

        return [
            'documentId' => (string) $document->id,
            'title' => $document->title,
            'status' => $document->status->value,
            'version' => $currentVersion->versionNumber,
            'markdown' => $currentVersion->markdownSource,
        ];
    }
}
