<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
        private SetDocumentTagsHandler $setTags,
        private DocumentSearchIndexer $searchIndexer,
    ) {
    }

    public function __invoke(CreateDocumentCommand $command): Document
    {
        $document = new Document(owner: $command->project->owner, project: $command->project, title: $command->title);
        $document->addVersion($command->markdown, $this->renderer->render($command->markdown), $command->description);

        // Persisted but deliberately not flushed: SetDocumentTagsHandler owns the
        // only flush, so a tag name it rejects aborts before any row is written.
        // Flushing here instead would commit the document, hand the caller an
        // error instead of its URL, and leave a duplicate behind on the retry.
        $this->em->persist($document);
        ($this->setTags)(new SetDocumentTagsCommand($document, $command->tagNames));

        // After setTags, because that is the flush: the indexer reads the rows
        // back over SQL, so it sees nothing until they exist.
        $this->searchIndexer->index($document);

        return $document;
    }
}
