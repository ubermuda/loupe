<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentSearchIndexer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class RenameDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private DocumentSearchIndexer $searchIndexer,
    ) {
    }

    public function __invoke(RenameDocumentCommand $command): Document
    {
        $document = $command->document;
        $title = trim($command->title);

        if ('' === $title) {
            throw new DomainErrors(['title' => 'review.rename.error.blank']);
        }

        if (mb_strlen($title) > Document::MAX_TITLE_LENGTH) {
            throw new DomainErrors(['title' => 'review.rename.error.too_long']);
        }

        $document->title = $title;
        $this->em->flush();

        // The title is the highest-weighted half of the vector, so a rename that
        // skipped this would keep matching the old title and miss the new one.
        $this->searchIndexer->index($document);

        // Neither title is recorded. Both are text a person wrote, and the
        // audit context carries ids, counts, flags and enum values only. The
        // document is the subject, so the record still says what was renamed.
        $this->auditor->record(
            'review.document.renamed',
            AuditOutcome::Success,
            [
                'documentId' => (string) $document->id,
                'projectId' => (string) $document->project->id,
            ],
            new AuditSubject('document', (string) $document->id),
        );

        return $document;
    }
}
