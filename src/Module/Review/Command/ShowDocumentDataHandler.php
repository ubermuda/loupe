<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentVersionRepository;

/**
 * @phpstan-type DocumentPayload array{documentId: string, title: string, status: string, archived: bool, archiveReason: ?string, version: int, versionDescription: ?string, markdown: string, tags: list<string>, references: list<array{documentId: string, title: string, archived: bool}>, referencedBy: list<array{documentId: string, title: string, archived: bool}>}
 */
final readonly class ShowDocumentDataHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    /**
     * Returns the data of an already-authorized document.
     *
     * @return DocumentPayload
     */
    public function __invoke(ShowDocumentDataCommand $command): array
    {
        $document = $command->document;

        $currentVersion = $this->documentVersions->findLatest($document);

        // Revising replaces the whole reference set, so an agent that means to
        // add one link has to be able to read the ones already there.
        $references = [];
        foreach ($document->references as $reference) {
            $references[] = [
                'documentId' => (string) $reference->id,
                'title' => $reference->title,
                'archived' => null !== $reference->archivedAt,
            ];
        }

        // Kept as its own key rather than merged into `references`: the two
        // directions mean different things to a reader, and only the outgoing
        // set is writable. An audit answered by a plan written later cannot
        // mention that plan, so discovering it is the whole point of the link.
        $referencedBy = [];
        foreach ($document->referencedBy as $referrer) {
            $referencedBy[] = [
                'documentId' => (string) $referrer->id,
                'title' => $referrer->title,
                'archived' => null !== $referrer->archivedAt,
            ];
        }

        // document_set_tags replaces the whole set, so the same argument as
        // references applies: preserving a tag while changing one needs a read.
        $tags = [];
        foreach ($document->tags as $tag) {
            $tags[] = $tag->name;
        }

        return [
            'documentId' => (string) $document->id,
            'title' => $document->title,
            'status' => $document->status->value,
            'archived' => null !== $document->archivedAt,
            // Always present, null unless the archiving stated one — a caller
            // reading a key that appears and disappears has to guess which of
            // the two it is looking at.
            'archiveReason' => $document->archiveReason,
            'version' => $currentVersion->versionNumber,
            'versionDescription' => $currentVersion->description,
            'markdown' => $currentVersion->markdownSource,
            'tags' => $tags,
            'references' => $references,
            'referencedBy' => $referencedBy,
        ];
    }
}
