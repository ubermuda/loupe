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
    public function export(User $user): iterable
    {
        $documents = $this->documents->findByOwner($user);

        // Every collection read below is lazy, so reading them per row would put
        // the whole export at one query per document per collection. An account
        // being exported is exactly the case with many documents.
        $this->documents->preloadTags($documents);
        $this->documents->preloadVersions($documents);
        $this->documents->preloadReferences($documents);

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

            $series = $document->series?->name;

            // Outgoing only: what this document points at is the owner's own
            // statement, while an incoming link is someone else's.
            $references = [];
            foreach ($document->references as $reference) {
                $references[] = [
                    'id' => (string) $reference->id,
                    'title' => $reference->title,
                ];
            }

            yield [
                'id' => (string) $document->id,
                'project' => $document->project->name,
                'title' => $document->title,
                'language' => $document->searchLanguage->value,
                'tags' => $tags,
                'series' => $series,
                'seriesOrdinal' => $document->seriesOrdinal,
                'status' => $document->status->value,
                'archivedAt' => $document->archivedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $document->createdAt->format(\DateTimeInterface::ATOM),
                'references' => $references,
                'versions' => $versions,
            ];
        }
    }
}
