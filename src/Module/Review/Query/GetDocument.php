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
     * @return array{documentId: string, title: string, status: string, archived: bool, version: int, versionDescription: ?string, markdown: string}
     */
    public function __invoke(Document $document): array
    {
        $currentVersion = $this->documentVersions->findLatest($document);

        return [
            'documentId' => (string) $document->id,
            'title' => $document->title,
            'status' => $document->status->value,
            'archived' => null !== $document->archivedAt,
            'version' => $currentVersion->versionNumber,
            'versionDescription' => $currentVersion->description,
            'markdown' => $currentVersion->markdownSource,
        ];
    }
}
