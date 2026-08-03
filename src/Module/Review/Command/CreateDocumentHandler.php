<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;
use App\Module\Review\Service\DocumentReferenceValidator;
use App\Module\Review\Service\MarkdownRenderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CreateDocumentHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private MarkdownRenderer $renderer,
        private DocumentReferenceValidator $referenceValidator,
    ) {
    }

    public function __invoke(CreateDocumentCommand $command): Document
    {
        $document = new Document(owner: $command->project->owner, project: $command->project, title: $command->title);
        $document->addVersion($command->markdown, $this->renderer->render($command->markdown), $command->description);

        foreach ($this->referenceValidator->validated($command->project, null, $command->references) as $reference) {
            $document->references->add($reference);
        }

        $this->em->persist($document);
        $this->em->flush();

        return $document;
    }
}
