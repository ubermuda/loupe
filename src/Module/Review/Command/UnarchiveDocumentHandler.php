<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Event\DocumentStatusChanged;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UnarchiveDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
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
            $this->events->dispatch(new DocumentStatusChanged(
                $document->project->id ?? throw new \LogicException('A persisted project has an id.'),
                $document->id ?? throw new \LogicException('A persisted document has an id.'),
            ));
        }

        return $document;
    }
}
