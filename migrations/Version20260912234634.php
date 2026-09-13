<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912234634 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Make board_cards.status nullable and drop its index';
    }

    /**
     * Release 3a of the move from the status enum to column rows. This image no
     * longer maps status, so its inserts leave it null, and the previous image
     * hydrates a null without error. No query of the previous image filters on
     * status, and a separate index covers project_id.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_board_cards_board_order');
        $this->addSql('ALTER TABLE board_cards ALTER status DROP NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Cut to the old width. The image that mirrors the slug here never reads the value, and the image before it knows four short slugs.
        $this->addSql('UPDATE board_cards SET status = LEFT(k.slug, 20) FROM board_columns k WHERE k.id = board_cards.column_id');
        $this->addSql('ALTER TABLE board_cards ALTER status SET NOT NULL');
        $this->addSql('CREATE INDEX idx_board_cards_board_order ON board_cards (project_id, status, priority, "position")');
    }
}
