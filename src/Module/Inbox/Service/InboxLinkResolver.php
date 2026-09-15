<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;

/**
 * Resolves the card and document ids an item links to, refusing any id that
 * names nothing in the project. The schema does not enforce the project, so
 * this is the check.
 *
 * Called before a handler opens its transaction: a DomainErrors thrown inside
 * one rolls it back and closes the EntityManager.
 */
final readonly class InboxLinkResolver
{
    public const string CARD_UNKNOWN = 'inbox.item.error.card_unknown';
    public const string DOCUMENT_UNKNOWN = 'inbox.item.error.document_unknown';

    public function __construct(
        private CardRepository $cards,
        private DocumentRepository $documents,
    ) {
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Card>
     */
    public function cards(Project $project, array $ids, string $field): array
    {
        $cards = [];
        foreach ($this->distinct($ids) as $id) {
            $card = $this->cards->findOneByIdAndProjectId($id, (string) $project->id)
                ?? throw new DomainErrors([$field => self::CARD_UNKNOWN]);
            // Keyed by the resolved id: two spellings of one UUID name one card.
            $cards[(string) $card->id] = $card;
        }

        return array_values($cards);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Document>
     */
    public function documents(Project $project, array $ids, string $field): array
    {
        $documents = [];
        foreach ($this->distinct($ids) as $id) {
            $document = $this->documents->findOneByIdAndProjectId($id, (string) $project->id)
                ?? throw new DomainErrors([$field => self::DOCUMENT_UNKNOWN]);
            $documents[(string) $document->id] = $document;
        }

        return array_values($documents);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function distinct(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(trim(...), $ids), static fn (string $id): bool => '' !== $id)));
    }
}
