<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ArchiveDocumentHandler
{
    public function __construct(
        private DocumentRepository $documents,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ArchiveDocumentCommand $command): Document
    {
        $document = $command->document;

        // No reason and a blank one are different things. Null is the app's
        // archive button, which has no field to fill in; a string of spaces is
        // a caller that was asked for a reason and answered with nothing, and
        // is rejected rather than stored as an explanation that says nothing.
        //
        // Outside the transaction below because it writes nothing: rolling back
        // for it would roll back an empty unit of work.
        $reason = null === $command->reason ? null : trim($command->reason);
        if ('' === $reason) {
            throw new DomainErrors(['reason' => 'review.archive.error.reason_blank']);
        }

        return $this->em->wrapInTransaction(function () use ($document, $reason): Document {
            // The check and the write are serialized on the row. Two callers
            // archiving the same live document at once would otherwise both find
            // it live, and the second flush would replace the first caller's
            // reason with its own — a stamp differing by milliseconds would not
            // be worth this, but a reason is a sentence a reviewer reads, and
            // whichever request committed last is an arbitrary way to pick it.
            //
            // The race itself is not expressible in a test: every test runs
            // inside one connection's transaction, so two overlapping database
            // transactions cannot exist. The sequential re-archive tests are the
            // regression guard, and this is verified by review.
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
                // Only when the loaded copy disagrees with the row, which means
                // another transaction archived this document while this one
                // waited for the lock: the caller is handed what is stored
                // rather than the nulls it loaded before the winner committed.
                // The loaded values are otherwise left exactly as they are — the
                // column is TIMESTAMP(0), so re-reading them would quietly drop
                // the sub-second part of a timestamp this process already holds.
                if (null === $document->archivedAt) {
                    $document->archivedAt = $stored['archivedAt'];
                    $document->archiveReason = $stored['archiveReason'];
                }

                return $document;
            }

            $document->archivedAt = new \DateTimeImmutable();
            $document->archiveReason = $reason;
            $this->em->flush();

            $this->logger->info('review.document.archived', [
                'document' => (string) $document->id,
                'project' => (string) $document->project->id,
            ]);

            return $document;
        });
    }
}
