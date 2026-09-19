<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

/**
 * Puts a card in its new place on the board, and renumbers what that disturbs.
 *
 * Ranks are plain integers renumbered per column rather than fractional, so a
 * column's order is readable in the table and an ORDER BY needs no tie-break.
 * Every open column a move touches comes out numbered from 0 with no gaps.
 *
 * The caller owns the transaction, the project lock and the flush. A move reads
 * the column the card sits in and renumbers the one it leaves, so the caller
 * must call CardRepository::refreshColumn() under its lock first. Nothing here
 * writes to the database, so a move and the renumbering it causes land together
 * or not at all.
 */
final readonly class CardMover
{
    /** A rank past the end of a column, which place() clamps to the end. */
    public const int END_OF_COLUMN = \PHP_INT_MAX;

    public function __construct(
        private CardRepository $cards,
        private CardGroupOrder $groupOrder,
    ) {
    }

    public function move(Card $card, BoardColumn $column, ?int $position = null): CardMove
    {
        if ($column->project !== $card->project) {
            throw new \LogicException('A card moves only to a column of its own board.');
        }

        $move = new CardMove($card->column);
        $staysInColumn = $move->fromColumn === $column;

        $card->column = $column;

        if ($column->terminal) {
            // A terminal column sorts by completion and maintains no position,
            // so the rank is parked at 0. A move between two terminal columns
            // keeps the moment the card was first finished.
            $card->completedAt ??= new \DateTimeImmutable();
            $card->position = 0;
        } else {
            $card->completedAt = null;

            if ($staysInColumn || null !== $position) {
                // No target rank means the end of the column, which place()
                // clamps to. Going through it rather than through
                // nextPosition() is what stops the old rank becoming a gap,
                // and it ranks a card that arrives from another column.
                $this->groupOrder->place($card, $position ?? self::END_OF_COLUMN);
            } else {
                $card->position = $this->cards->nextPosition($column);
            }
        }

        if (!$staysInColumn) {
            $this->groupOrder->compact($move->fromColumn, $card);
        }

        $card->updatedAt = new \DateTimeImmutable();

        return $move;
    }
}
