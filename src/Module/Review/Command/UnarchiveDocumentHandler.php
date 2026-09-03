<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UnarchiveDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UnarchiveDocumentCommand $command): Document
    {
        $document = $command->document;

        if (null !== $document->archivedAt) {
            $document->archivedAt = null;
            // The reason goes with the archiving it explained: a document back
            // in the list must not still read "archived because superseded".
            $document->archiveReason = null;
            $this->em->flush();

            $this->auditor->record(
                'review.document_unarchived',
                AuditOutcome::Success,
                [
                    'documentId' => (string) $document->id,
                    'projectId' => (string) $document->project->id,
                ],
                new AuditSubject('document', (string) $document->id),
            );
        }

        return $document;
    }
}
