<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\Tag;
use App\Module\Review\Service\DocumentReferenceValidator;
use App\Module\Review\Service\DocumentSearchIndexer;
use App\Module\Review\Service\DocumentTagApplier;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
        private DocumentTagApplier $tagApplier,
        private DocumentReferenceValidator $referenceValidator,
        private DocumentSearchIndexer $searchIndexer,
        private Auditor $auditor,
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

        // The same rules a rename enforces. Creation went without them, so a
        // document could be born with a title no rename would ever accept.
        $title = trim($command->title);
        if ('' === $title) {
            throw new DomainErrors(['title' => 'review.create.error.blank']);
        }
        if (mb_strlen($title) > Document::MAX_TITLE_LENGTH) {
            throw new DomainErrors(['title' => 'review.create.error.too_long']);
        }

        $document = new Document(owner: $command->project->owner, project: $command->project, title: $title);
        $document->addVersion($command->markdown, $this->renderer->render($command->markdown), $command->description);

        // Also before persist(), for the same reason: this rejects a reference
        // the project may not point at.
        $references = $this->referenceValidator->validated($command->project, null, $command->references);
        foreach ($references as $reference) {
            $document->addReference($reference);
        }

        // One flush, so the document, its tags and its references are written
        // together or not at all.
        $this->em->persist($document);
        $this->tagApplier->apply($document, $command->tagNames);
        $this->em->flush();

        // Between the flush and the index. Before the flush the document has no
        // committed id, and after the index a throwing indexer would leave the
        // document written with no record that it was created.
        $this->auditor->record(
            'review.document_created',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $command->project->id,
                'tagCount' => \count($document->tags),
                // The validated list, not what was asked for: it drops a target
                // named twice, which is one link rather than two.
                'referenceCount' => \count($references),
            ],
            new AuditSubject('document', (string) $document->id),
        );

        // After the flush, because the indexer reads the rows back over SQL and
        // sees nothing until they exist.
        $this->searchIndexer->index($document);

        return $document;
    }
}
