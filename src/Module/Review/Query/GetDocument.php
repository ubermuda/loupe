<?php

declare(strict_types=1);

namespace App\Module\Review\Query;

use App\Module\Project\Entity\Project;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\Uid\Uuid;

final readonly class GetDocument
{
    public function __construct(
        private DocumentRepository $documents,
    ) {
    }

    /**
     * Returns document data scoped to the given project.
     *
     * @return array{documentId: string, title: string, status: string, version: int, markdown: string}
     *
     * @throws DocumentNotFound if no document with the given id belongs to $project
     */
    public function __invoke(Uuid $documentId, Project $project): array
    {
        $document = $this->documents->findOneByIdAndProject($documentId, $project);

        if (null === $document) {
            throw DocumentNotFound::forId($documentId);
        }

        $currentVersion = $document->currentVersion();

        return [
            'documentId' => (string) $document->id,
            'title' => $document->title,
            'status' => $document->status->value,
            'version' => $currentVersion->versionNumber,
            'markdown' => $currentVersion->markdownSource,
        ];
    }
}
