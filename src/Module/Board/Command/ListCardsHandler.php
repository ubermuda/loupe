<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;

/**
 * Reads one page of a project's board, filtered by column, type, priority and
 * reporter.
 *
 * It owns the whole rule, so every entry point gets the same answer: the paging
 * is clamped into range and the repository is read once.
 */
final readonly class ListCardsHandler
{
    public const int DEFAULT_PER_PAGE = 50;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(ListCardsCommand $command): ListCardsView
    {
        // Clamped rather than refused: an out-of-range page should read empty,
        // not fail the call.
        $page = max(1, $command->page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));

        $cards = $this->cards->findForBoard(
            $command->project,
            $command->status,
            $command->type,
            $command->priority,
            $command->reporter,
        );

        $total = \count($cards);
        // Board order is up to four differently-ordered queries concatenated in
        // PHP, so one LIMIT cannot express it and the page is cut here instead.
        // The offset is capped before the multiplication, because a page near
        // PHP_INT_MAX would overflow to a float and array_slice() refuses it.
        $offset = $page - 1 > intdiv($total, $perPage) ? $total : ($page - 1) * $perPage;

        return new ListCardsView(
            cards: \array_slice($cards, $offset, $perPage),
            page: $page,
            perPage: $perPage,
            total: $total,
            hasMore: $offset + $perPage < $total,
        );
    }
}
