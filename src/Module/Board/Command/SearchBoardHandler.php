<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;

/**
 * Reads one page of the cards matching a full-text query.
 *
 * It owns the whole rule, so every entry point gets the same answer: the query
 * is trimmed and a blank one is refused, the paging is clamped into range, and
 * the repository is read once.
 */
final readonly class SearchBoardHandler
{
    public const int DEFAULT_PER_PAGE = 25;

    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private CardRepository $cards,
    ) {
    }

    public function __invoke(SearchBoardCommand $command): SearchBoardView
    {
        $query = trim($command->query);
        if ('' === $query) {
            // websearch_to_tsquery('') matches nothing and raises nothing, so a
            // blank query would read as an empty board rather than as a mistake.
            throw new DomainErrors(['query' => 'board.card.error.search_query_blank']);
        }

        // Clamped rather than refused: an out-of-range page should read empty,
        // not fail the call.
        $perPage = min(self::MAX_PER_PAGE, max(1, $command->perPage));
        // A page near PHP_INT_MAX overflows the offset multiplication to a
        // float, which setFirstResult() then refuses.
        $page = min(max(1, $command->page), intdiv(\PHP_INT_MAX, $perPage));

        $paginator = $this->cards->searchByProject($command->project, $query, $page, $perPage);
        $total = \count($paginator);
        /** @var list<Card> $cards */
        $cards = array_values(iterator_to_array($paginator, false));

        return new SearchBoardView(
            cards: $cards,
            page: $page,
            perPage: $perPage,
            total: $total,
            hasMore: ($page - 1) * $perPage + \count($cards) < $total,
        );
    }
}
