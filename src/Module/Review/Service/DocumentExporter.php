<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Review\Repository\DocumentRepository;

final readonly class DocumentExporter implements UserDataExporterInterface
{
    public function __construct(
        private DocumentRepository $documents,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'documents.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $documents = $this->documents->findByOwner($user);

        // Both collections are lazy, so reading them per row would put the whole
        // export at one query per document, twice over. An account being exported
        // is exactly the case with many of them.
        $this->documents->preloadTags($documents);
        $this->documents->preloadVersions($documents);

        $rows = [];
        foreach ($documents as $document) {
            $versions = [];
            foreach ($document->versions as $version) {
                $versions[] = [
                    'id' => (string) $version->id,
                    'versionNumber' => $version->versionNumber,
                    'description' => $version->description,
                    'markdownSource' => $version->markdownSource,
                    'createdAt' => $version->createdAt->format(\DateTimeInterface::ATOM),
                ];
            }

            $tags = [];
            foreach ($document->tags as $tag) {
                $tags[] = $tag->name;
            }

            $rows[] = [
                'id' => (string) $document->id,
                'project' => $document->project->name,
                'title' => $document->title,
                'tags' => $tags,
                'status' => $document->status->value,
                'archivedAt' => $document->archivedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $document->createdAt->format(\DateTimeInterface::ATOM),
                'versions' => $versions,
            ];
        }

        return $rows;
    }
}
