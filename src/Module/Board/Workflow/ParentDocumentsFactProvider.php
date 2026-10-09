<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class ParentDocumentsFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return ParentDocumentsFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'parent-documents';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        $parent = null === $card->parentId ? null : $this->cards->find($card->parentId);

        return new ParentDocumentsFacts(null === $parent ? [] : DocumentsFactProvider::documentFacts($this->cardDocuments, $parent));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof ParentDocumentsFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::documents($facts->documents);
    }
}
