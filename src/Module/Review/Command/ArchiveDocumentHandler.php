<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class ArchiveDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ArchiveDocumentCommand $command): Document
    {
        $document = $command->document;

        // Archiving an already-archived document keeps the original timestamp:
        // it records when the document left the list, and nothing happened here.
        // The read and the write are not locked together, so two simultaneous
        // archives can both pass this check — the whole consequence is a stamp
        // differing by milliseconds, which is not worth serializing for.
        if (null === $document->archivedAt) {
            $document->archivedAt = new \DateTimeImmutable();
            $this->em->flush();

            $this->logger->info('review.document.archived', [
                'document' => (string) $document->id,
                'project' => (string) $document->project->id,
            ]);
        }

        return $document;
    }
}
