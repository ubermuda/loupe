<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Doctrine\FullTextSearch;
use App\Module\Board\Entity\Card;
use Doctrine\DBAL\Connection;

/**
 * Rebuilds a card's search vector from its title and its body.
 *
 * Every write that changes an input calls this: creating a card, and updating
 * one whose title or body moved. Both callers already hold a transaction for
 * the board's position lock, so the UPDATE runs on the same connection inside
 * it and the vector commits with the row.
 *
 * A database trigger would do the same work invisibly; this codebase has none,
 * and keeping it in the handlers is what makes it greppable.
 */
final readonly class CardSearchIndexer
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function index(Card $card): void
    {
        // The weights and the expression are written out again in the migration
        // that added the column. A migration is a frozen record of what already
        // ran, so it must not change meaning when this expression is next edited.
        $this->connection->executeStatement(
            \sprintf(
                <<<'SQL'
                    UPDATE board_cards
                    SET search_vector = setweight(to_tsvector(search_language::regconfig, title), '%1$s')
                        || setweight(to_tsvector(search_language::regconfig, body), '%2$s')
                    WHERE id = :id
                    SQL,
                FullTextSearch::TITLE_WEIGHT,
                FullTextSearch::BODY_WEIGHT,
            ),
            ['id' => (string) $card->id],
        );
    }
}
