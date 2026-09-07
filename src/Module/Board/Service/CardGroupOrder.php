<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;

/**
 * Keeps a (project, status, priority) group numbered from 0 with no gaps.
 *
 * Every write that adds a card to a group, takes one out, or deletes one goes
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

    /** Puts the card at the wanted rank in its own group, then renumbers the group from 0. */
    public function place(Card $card, int $position): void
    {
        $members = $this->groupWithout($card->project, $card->status, $card->priority, $card);

        $target = max(0, min($position, \count($members)));
        array_splice($members, $target, 0, [$card]);

        $this->renumber($members);
    }

    /**
     * Closes the gap a card leaves in a group. Done keeps no position, so a
     * group in that column is left alone.
     *
     * $leaving is still in the group in the database, because it has not been
     * flushed out of it yet, so it is dropped by identity.
     */
    public function compact(Project $project, CardStatus $status, CardPriority $priority, Card $leaving): void
    {
        if (CardStatus::Done === $status) {
            return;
        }

        $this->renumber($this->groupWithout($project, $status, $priority, $leaving));
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
    private function groupWithout(Project $project, CardStatus $status, CardPriority $priority, Card $excluded): array
    {
        return array_values(array_filter(
            $this->cards->findGroup($project, $status, $priority),
            static fn (Card $member): bool => $member !== $excluded,
        ));
    }
}
