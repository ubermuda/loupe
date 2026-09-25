<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

/**
 * The cards the board shows in one column, in the order it shows them. The
 * board page and the one-card placement both read a column here, so the
 * order and the column counts cannot drift apart.
 */
final readonly class BoardColumnCards
{
    /**
     * How far back a terminal column reads.
     *
     * A terminal column only ever grows, so it shows a recent slice and the
     * history page carries the rest.
     */
    public const int TERMINAL_WINDOW_DAYS = 7;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    /** @return list<Card> */
    public function shown(BoardColumn $column): array
    {
        if ($column->terminal) {
            return $this->cards->findCompletedSince(
                $column,
                new \DateTimeImmutable(\sprintf('-%d days', self::TERMINAL_WINDOW_DAYS)),
            );
        }

        return $this->cards->findForBoard([$column]);
    }
}
