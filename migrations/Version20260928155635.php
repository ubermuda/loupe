<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928155635 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Turn the default board column into the Backlog, with the slug backlog, and put it first';
    }

    public function up(Schema $schema): void
    {
        $defaults = $this->connection->fetchFirstColumn(
            'SELECT project_id FROM board_columns GROUP BY project_id HAVING COUNT(*) FILTER (WHERE is_default) <> 1 ORDER BY project_id',
        );
        $this->abortIf([] !== $defaults, 'Each board needs exactly one default column. Fix these projects by hand: '.implode(', ', $defaults));

        $lastTerminal = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT o.project_id FROM board_columns o
            WHERE o.slug = 'backlog' AND NOT o.is_default AND o.terminal
              AND NOT EXISTS (SELECT 1 FROM board_columns t WHERE t.project_id = o.project_id AND t.terminal AND t.id <> o.id)
            ORDER BY o.project_id
            SQL);
        $this->abortIf([] !== $lastTerminal, 'The column named backlog is the only terminal column of its board, and this migration folds it into the Backlog. Mark another column terminal in these projects first: '.implode(', ', $lastTerminal));

        // Another column that holds the slug backlog gives its cards to the end of the Backlog, in rank order.
        $this->addSql(<<<'SQL'
            UPDATE board_cards c
            SET column_id = ranked.backlog_id, position = ranked.tail + ranked.rank, completed_at = NULL, updated_at = NOW()
            FROM (
                SELECT s.id, b.id AS backlog_id,
                       row_number() OVER (PARTITION BY s.column_id ORDER BY s.position, s.completed_at, s.created_at, s.number) - 1 AS rank,
                       (SELECT COALESCE(MAX(t.position) + 1, 0) FROM board_cards t WHERE t.column_id = b.id) AS tail
                FROM board_cards s
                JOIN board_columns o ON o.id = s.column_id AND o.slug = 'backlog' AND NOT o.is_default
                JOIN board_columns b ON b.project_id = o.project_id AND b.is_default
            ) ranked
            WHERE c.id = ranked.id
            SQL);
        $this->addSql(<<<'SQL'
            DELETE FROM board_columns o
            WHERE o.slug = 'backlog' AND NOT o.is_default
              AND EXISTS (SELECT 1 FROM board_columns b WHERE b.project_id = o.project_id AND b.is_default)
            SQL);

        $this->addSql("UPDATE board_columns SET slug = 'backlog', label = 'board.card.status.backlog' WHERE is_default");
        $this->addSql(<<<'SQL'
            UPDATE board_columns k
            SET position = ordered.position
            FROM (
                SELECT id, row_number() OVER (PARTITION BY project_id ORDER BY is_default DESC, position, id) - 1 AS position
                FROM board_columns
            ) ordered
            WHERE k.id = ordered.id AND k.position <> ordered.position
            SQL);
    }

    /** The folded columns and the old names are gone, so nothing is put back. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The folded columns and the old column names are gone.');
    }
}
