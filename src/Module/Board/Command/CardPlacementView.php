<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\CardBadge;
use App\Module\Board\Service\CardState;

/** Where one card sits on the board page, and the count of every column. */
final readonly class CardPlacementView
{
    public function __construct(
        /** Null when the board does not show the card: it is deleted, or outside its terminal window. */
        public ?Card $card,
        /** The Backlog for a lane epic there, which heads its lane and has no list row. */
        public ?BoardColumn $column,
        /** The id of the card before it in its column, or in its lane cell on a board with lanes. Null when it comes first. */
        public ?string $after,
        /** The id of the card before it in the list view, which runs column by column. */
        public ?string $rowAfter,
        public int $pendingComments,
        public int $documentCount,
        /** @var array<string, int> column id => the number of cards the column shows */
        public array $counts,
        /** @var array<string, int> terminal column id => every card the column holds */
        public array $terminalTotals,
        /** The done and total children of an epic, null for any other card. */
        public ?CardProgress $progress,
        /** The lane key the board page gives the card: a lane epic id or "other". Null on a board with no lane, and for a lane head. */
        public ?string $lane,
        /** Whether the card is a lane epic, which the board draws as the head of its lane. */
        public bool $laneHead,
        /** For a lane head, the epic of the lane before it on the page, or null when its lane comes first. */
        public ?string $laneAfter,
        /** For a lane head, its Up next deck, or null when the epic has no Backlog child. */
        public ?LaneDeckView $deck = null,
        /** @var list<CardBadge> */
        public array $badges = [],
        /** For a Backlog card the board does not show, the lane epic whose Up next deck shows it. */
        public ?string $deckEpic = null,
        public ?CardState $state = null,
    ) {
    }
}
