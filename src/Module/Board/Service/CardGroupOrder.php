<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

/**
 * Keeps an open column numbered from 0 with no gaps.
 *
 * Every write that adds a card to a column, takes one out, or deletes one goes
 * through here, so the rank the board reads and the MCP payload reports is the
 * card's real place in its column rather than a number with holes in it.
 *
 * The caller flushes. Nothing here writes to the database itself, so a move and
 * the renumbering it causes land together or not at all.
 */
final readonly class CardGroupOrder
{
    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** Puts the card at the wanted rank in its own column, then renumbers the column from 0. */
    public function place(Card $card, int $position): void
    {
        $members = $this->columnWithout($card->column, $card);

        $target = max(0, min($position, \count($members)));
        array_splice($members, $target, 0, [$card]);

        $this->renumber($members);
    }

    /**
     * Closes the gap a card leaves in a column. A terminal column keeps no
     * position, so it is left alone.
     *
     * $leaving is still in the column in the database, because it has not been
     * flushed out of it yet, so it is dropped by identity.
     */
    public function compact(BoardColumn $column, Card $leaving): void
    {
        if ($column->terminal) {
            return;
        }

        $this->renumber($this->columnWithout($column, $leaving));
    }

    /**
     * @param list<Card> $members
     */
    private function renumber(array $members): void
    {
        foreach ($members as $index => $member) {
            $member->position = $index;
        }
    }

    /** @return list<Card> */
    private function columnWithout(BoardColumn $column, Card $excluded): array
    {
        return array_values(array_filter(
            $this->cards->findRanked($column),
            static fn (Card $member): bool => $member !== $excluded,
        ));
    }
}
