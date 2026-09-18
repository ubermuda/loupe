<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918102619 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rank each open board column as one list, and stop requiring board_cards.priority';
    }

    /**
     * Each open column keeps the order it showed: highest priority first, then
     * the old rank. The previous image still reads and writes priority, so the
     * column and its index stay, and the default fills it for this image's
     * inserts. A later release drops both.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE board_cards c
             SET position = ranked.rank
             FROM (
                 SELECT s.id,
                        row_number() OVER (PARTITION BY s.column_id ORDER BY s.priority, s.position, s.created_at, s.id) - 1 AS rank
                 FROM board_cards s
                 JOIN board_columns k ON k.id = s.column_id
                 WHERE k.terminal = false
             ) ranked
             WHERE c.id = ranked.id AND c.position <> ranked.rank',
        );
        $this->addSql('ALTER TABLE board_cards ALTER priority SET DEFAULT 20');
        $this->addSql('CREATE INDEX idx_board_cards_column_position ON board_cards (column_id, position)');
    }

    /** The ranks stay as up() left them. The previous image reads them in the same order inside each priority. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_column_position');
        $this->addSql('ALTER TABLE board_cards ALTER priority DROP DEFAULT');
    }
}
