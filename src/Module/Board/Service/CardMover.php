<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;

/**
 * Puts a card in its new place on the board, and renumbers what that disturbs.
 *
 * Ranks are plain integers renumbered per group rather than fractional, so a
 * group's order is readable in the table and an ORDER BY needs no tie-break.
 * A group holds the cards of one (project, status, priority) triple, and every
 * group a move touches comes out numbered from 0 with no gaps.
 *
 * The caller owns the transaction, the project lock and the flush. A move reads
 * the group the card sits in and renumbers the one it leaves, so the caller must
 * call CardRepository::refreshGroup() under its lock first. Nothing here writes
 * to the database, so a move and the renumbering it causes land together or not
 * at all.
 */
final readonly class CardMover
{
    public function __construct(
        private CardRepository $cards,
        private CardGroupOrder $groupOrder,
    ) {
    }

    public function move(Card $card, CardStatus $status, CardPriority $priority, ?int $position = null): CardMove
    {
        $move = new CardMove($card->status, $card->priority);
        $staysInGroup = $move->fromStatus === $status && $move->fromPriority === $priority;

        $card->status = $status;
        $card->priority = $priority;

        if (CardStatus::Done === $status) {
            // Done sorts by completion and maintains no position, so the rank
            // is parked at 0 and the card keeps the moment it was first
            // finished.
            $card->completedAt ??= new \DateTimeImmutable();
            $card->position = 0;
        } else {
            $card->completedAt = null;

            if ($staysInGroup) {
                // No target rank means the end of the group, which place()
                // clamps to. Going through it rather than through
                // nextPosition() is what stops the old rank becoming a gap.
                $this->groupOrder->place($card, $position ?? \PHP_INT_MAX);
            } else {
                $card->position = $this->cards->nextPosition($card->project, $status, $priority);
            }
        }

        if (!$staysInGroup) {
            $this->groupOrder->compact($card->project, $move->fromStatus, $move->fromPriority, $card);
        }

        $card->updatedAt = new \DateTimeImmutable();

        return $move;
    }
}
