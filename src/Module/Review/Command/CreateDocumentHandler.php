<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DocumentReferenceValidator;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
        private SetDocumentTagsHandler $setTags,
        private DocumentReferenceValidator $referenceValidator,
        private DocumentSearchIndexer $searchIndexer,
    ) {
    }

    public function __invoke(CreateDocumentCommand $command): Document
    {
        // Before persist(), not after: persist() schedules the insert
        // immediately, and declining to flush does not unschedule it. A rejected
        // name would otherwise leave the document sitting in the unit of work for
        // the next flush by anyone sharing this EntityManager — a long-lived
        // process, or the worker — to write on its behalf.
        Tag::normalizeNames($command->tagNames);

        $document = new Document(owner: $command->project->owner, project: $command->project, title: $command->title);
        $document->addVersion($command->markdown, $this->renderer->render($command->markdown), $command->description);

        // Also before persist(), for the same reason: this rejects a reference
        // the project may not point at.
        foreach ($this->referenceValidator->validated($command->project, null, $command->references) as $reference) {
            $document->addReference($reference);
        }

        // SetDocumentTagsHandler owns the only flush, so the document, its tags
        // and its references are written together or not at all.
        $this->em->persist($document);
        ($this->setTags)(new SetDocumentTagsCommand($document, $command->tagNames));

        // After setTags, because that is the flush: the indexer reads the rows
        // back over SQL, so it sees nothing until they exist.
        $this->searchIndexer->index($document);

        return $document;
    }
}
