<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UnarchiveDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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

            $this->logger->info('review.document.unarchived', [
                'document' => (string) $document->id,
                'project' => (string) $document->project->id,
            ]);
        }

        return $document;
    }
}
