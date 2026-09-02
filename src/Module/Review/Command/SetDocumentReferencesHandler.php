<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentReferenceValidator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Replaces the set of documents a document points at.
 *
 * References belong to the document rather than to one of its versions, so
 * changing them mints no version: linking two documents that already exist does
 * not re-anchor their comments or drop their highlights.
 *
 * Replace rather than add-and-remove, matching the tag set: it is idempotent,
 * and a caller that knows the intended set should not have to diff against the
 * current one.
 */
final readonly class SetDocumentReferencesHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentReferenceValidator $referenceValidator,
        private Auditor $auditor,
    ) {
    }

    /** @return list<Document> the documents the source now points at */
    public function __invoke(SetDocumentReferencesCommand $command): array
    {
        $document = $command->document;

        // Validated before the collection below is touched, so a rejected set
        // leaves the document pointing exactly where it did.
        $references = $this->referenceValidator->validated($document->project, $document, $command->references);

        $document->clearReferences();
        foreach ($references as $reference) {
            $document->addReference($reference);
        }

        $this->em->flush();

        $this->auditor->record(
            'review.document_references_updated',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $document->project->id,
                'referenceCount' => \count($references),
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $references;
    }
}
