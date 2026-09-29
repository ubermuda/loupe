<?php

declare(strict_types=1);

namespace App\Module\Board\View;

use App\Module\Board\Entity\Card;

/**
 * How a Backlog page changed across a move. Removing rows keeps the boxes
 * ticked on the others, but it is enough only when the page lost rows and kept
 * the rest in order. A move can take more rows than it names, because closing
 * an epic's last child closes the epic.
 */
final readonly class BacklogPageChange
{
    /** @param list<string> $goneIds the rows the page lost */
    private function __construct(
        public bool $redraws,
        public array $goneIds,
    ) {
    }

    /**
     * @param list<string> $shownIds the page before the move
     * @param list<Card>   $items    the page after the move
     */
    public static function between(array $shownIds, array $items): self
    {
        $ids = array_map(static fn (Card $card): string => (string) $card->id, $items);

        return new self(
            [] === $ids || $ids !== array_values(array_intersect($shownIds, $ids)),
            array_values(array_diff($shownIds, $ids)),
        );
    }
}
