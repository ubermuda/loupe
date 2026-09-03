<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class ArchiveDocumentHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(ArchiveDocumentCommand $command): Document
    {
        $document = $command->document;

        // No reason and a blank one differ: null is the app's archive button,
        // which has no field, while spaces are a caller that was asked and
        // answered with nothing. Outside the transaction because it writes
        // nothing.
        $reason = null === $command->reason ? null : trim($command->reason);
        if ('' === $reason) {
            throw new DomainErrors(['reason' => 'review.archive.error.reason_blank']);
        }

        $archived = false;

        $this->em->wrapInTransaction(function () use ($document, $reason, &$archived): void {
            // Serializes the check and the write on the row: two callers
            // archiving at once would both find it live, and the later flush
            // would replace the first caller's reason with its own. A reason is
            // a sentence a reviewer reads, so "whichever committed last" is an
            // arbitrary way to pick it.
            $this->em->lock($document, LockMode::PESSIMISTIC_WRITE);

            // lock() takes the row but leaves the loaded entity as it was, so the
            // guard reads the stored state back rather than trusting what was
            // loaded before the lock was held.
            $stored = $this->documents->archiveStateOf($document);

            // Archiving an already-archived document keeps the original
            // timestamp and the original reason: they record when and why the
            // document left the list, and nothing happened here. Restating a
            // reason therefore means restoring the document and archiving it
            // again.
            if (null !== $stored['archivedAt']) {
                // Only when the loaded copy disagrees with the row — another
                // transaction archived this while we waited for the lock.
                // Otherwise left alone: the column is TIMESTAMP(0), so
                // re-reading drops the sub-second part this process holds.
                if (null === $document->archivedAt) {
                    $document->archivedAt = $stored['archivedAt'];
                    $document->archiveReason = $stored['archiveReason'];
                }

                return;
            }

            $document->archivedAt = new \DateTimeImmutable();
            $document->archiveReason = $reason;
            $this->em->flush();

            $archived = true;
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The reason
        // stays out, because it is a sentence a reviewer wrote.
        if ($archived) {
            $this->auditor->record(
                'review.document_archived',
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
