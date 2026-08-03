<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Exception\DomainErrors;
use App\Module\Review\Entity\Document;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RenameDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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

        $previousTitle = $document->title;
        $document->title = $title;
        $this->em->flush();

        $this->logger->info('review.document.renamed', [
            'document' => (string) $document->id,
            'project' => (string) $document->project->id,
            'previousTitle' => $previousTitle,
            'title' => $title,
        ]);

        return $document;
    }
}
