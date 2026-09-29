<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Command\LaneDeckView;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Repository\CardRepository;

/** The Up next deck of each lane epic, in two queries whatever the size of the Backlog. */
final readonly class LaneDecks
{
    public const int DECK_SIZE = 8;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /**
     * @param list<string> $epicIds
     *
     * @return array<string, LaneDeckView> epic id => its deck; an epic with no Backlog child has no key
     */
    public function forEpics(BoardColumn $backlog, array $epicIds): array
    {
        if ([] === $epicIds) {
            return [];
        }

        $cardsByEpic = [];
        foreach ($this->cards->findDeckCards($backlog, $epicIds, self::DECK_SIZE) as $card) {
            $cardsByEpic[(string) $card->parent?->id][] = $card;
        }

        $counts = $this->cards->countChildrenIn($backlog, $epicIds);
        $decks = [];
        foreach ($epicIds as $epicId) {
            if (isset($counts[$epicId])) {
                $decks[$epicId] = new LaneDeckView($backlog, $cardsByEpic[$epicId] ?? [], $counts[$epicId]);
            }
        }

        return $decks;
    }
}
