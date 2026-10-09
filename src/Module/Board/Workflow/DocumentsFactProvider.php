<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardDocumentRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Workflow\Contract\CardSnapshot;
use App\Module\Workflow\Contract\DocumentFacts;
use App\Module\Workflow\Contract\FingerprintValue;

final readonly class DocumentsFactProvider extends BoardFactProvider
{
    public function __construct(
        private CardRepository $cards,
        private CardDocumentRepository $cardDocuments,
    ) {
    }

    #[\Override]
    public function factsClass(): string
    {
        return DocumentsFacts::class;
    }

    #[\Override]
    public function legacyGroup(): string
    {
        return 'documents';
    }

    #[\Override]
    public function build(CardSnapshot $card): object
    {
        return new DocumentsFacts(self::documentFacts($this->cardDocuments, $this->cards->find($card->id) ?? throw new \LogicException('A stored card has an id.')));
    }

    #[\Override]
    public function fingerprint(object $facts): mixed
    {
        $facts instanceof DocumentsFacts || throw new \LogicException('The provider fingerprints its own facts.');

        return FingerprintValue::documents($facts->documents);
    }

    /** @return list<DocumentFacts> */
    public static function documentFacts(CardDocumentRepository $cardDocuments, Card $card): array
    {
        return array_map(
            static fn (array $document): DocumentFacts => new DocumentFacts($document['tags'], $document['status'], $document['id']),
            $cardDocuments->findStatusesAndTagsForCard($card),
        );
    }
}
