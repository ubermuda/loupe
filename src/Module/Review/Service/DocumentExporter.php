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
        $rows = [];
        foreach ($this->documents->findByOwner($user) as $document) {
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

            $rows[] = [
                'id' => (string) $document->id,
                'project' => $document->project->name,
                'title' => $document->title,
                'status' => $document->status->value,
                'archivedAt' => $document->archivedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $document->createdAt->format(\DateTimeInterface::ATOM),
                'versions' => $versions,
            ];
        }

        return $rows;
    }
}
