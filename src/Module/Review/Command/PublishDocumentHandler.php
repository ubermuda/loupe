<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Event\DocumentStatusChanged;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Sends a draft to review. A document that is not a draft is left as it is,
 * and the handler returns false for it.
 */
final readonly class PublishDocumentHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(PublishDocumentCommand $command): bool
    {
        $document = $command->document;

        // The refusal leaves the closure as a value: an exception thrown inside
        // wrapInTransaction() closes the entity manager.
        $outcome = $this->em->wrapInTransaction(function () use ($document): bool|DomainErrors {
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            // lock() leaves the loaded entity as it was, so the guard reads the row.
            $stored = $this->documents->publishStateOf($document);

            if (null !== $stored['archivedAt']) {
                return new DomainErrors(['documentId' => 'review.publish.error.archived']);
            }

            if (DocumentStatus::Draft !== $stored['status']) {
                $document->status = $stored['status'];

                return false;
            }

            $document->status = DocumentStatus::InReview;
            $this->em->flush();

            return true;
        });

        if ($outcome instanceof DomainErrors) {
            throw $outcome;
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback.
        if ($outcome) {
            $this->auditor->record(
                'review.document_published',
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

        return $outcome;
    }
}
