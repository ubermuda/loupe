<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911161126 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add a weighted full-text search vector to board cards, backfilled from each card\'s title and body';
    }

    /**
     * Expand only: two nullable-or-defaulted columns and two indexes. The
     * previous image never selects either column, and its INSERT omits
     * search_language, which the DEFAULT then fills.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD search_vector TSVECTOR DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD search_language VARCHAR(20) DEFAULT \'english\' NOT NULL');

        // A card takes the language its project holds, the same rule a document
        // takes at creation. Every card predating this belongs to a project that
        // already carries one, so this is the only chance to read it across.
        $this->addSql(<<<'SQL'
            UPDATE board_cards c
            SET search_language = p.search_language
            FROM projects p
            WHERE p.id = c.project_id
            SQL);

        // Existing cards get their vector here rather than waiting for their next
        // edit. An unindexed row matches nothing, so card_search would come up
        // empty on every card that predates this.
        $this->addSql(<<<'SQL'
            UPDATE board_cards
            SET search_vector = setweight(to_tsvector(search_language::regconfig, title), 'A')
                || setweight(to_tsvector(search_language::regconfig, body), 'B')
            SQL);

        // Written by hand because DBAL's Postgres platform emits no USING clause:
        // a B-tree index on a tsvector is built happily and never used by @@.
        $this->addSql('CREATE INDEX idx_board_cards_search_vector ON board_cards USING gin (search_vector)');
        $this->addSql('CREATE INDEX idx_board_cards_project_search_language ON board_cards (project_id, search_language)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_project_search_language');
        $this->addSql('DROP INDEX idx_board_cards_search_vector');
        $this->addSql('ALTER TABLE board_cards DROP search_language');
        $this->addSql('ALTER TABLE board_cards DROP search_vector');
    }
}
