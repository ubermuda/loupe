<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
    ) {
    }

    public function __invoke(CreateDocumentCommand $command): Document
    {
        $document = new Document($command->owner, $command->title);
        $document->addVersion($command->markdown, $this->renderer->render($command->markdown));

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
