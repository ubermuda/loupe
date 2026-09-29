<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\Repository\DocumentVersionRepository;
use App\Module\Review\Security\DocumentVoter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The definitions a version's mentions can remind the reader of: its own first,
 * then those of each referenced document the reader can view, in reference order.
 */
final readonly class ReferenceDefinitionResolver
{
    public function __construct(
        private ReferenceReminderInjector $injector,
        private DocumentVersionRepository $documentVersions,
        private AuthorizationCheckerInterface $authorization,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, array{text: string, source: string|null, href: string}> keyed by ID
     */
    public function resolve(Document $document, DocumentVersion $version): array
    {
        $definitions = [];
        foreach ($this->injector->definitions($version->renderedHtml) as $id => $text) {
            $definitions[$id] = ['text' => $text, 'source' => null, 'href' => '#ref-'.$id];
        }

        $references = array_values(array_filter(
            $document->references->toArray(),
            fn (Document $reference): bool => $this->authorization->isGranted(DocumentVoter::VIEW, $reference),
        ));
        if ([] === $references) {
            return $definitions;
        }

        $latest = $this->documentVersions->findLatestByDocuments($references);
        foreach ($references as $reference) {
            $referenceVersion = $latest[(string) $reference->id] ?? null;
            if (null === $referenceVersion) {
                continue;
            }

            foreach ($this->injector->definitions($referenceVersion->renderedHtml) as $id => $text) {
                $definitions[$id] ??= [
                    'text' => $text,
                    'source' => $reference->title,
                    'href' => $this->urls->generate('app_document_review', [
                        'projectId' => (string) $reference->project->id,
                        'documentId' => (string) $reference->id,
                        '_fragment' => 'ref-'.$id,
                    ]),
                ];
            }
        }

        return $definitions;
    }
}
