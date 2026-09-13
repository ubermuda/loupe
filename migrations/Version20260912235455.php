<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912235455 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop board_cards.status and make board_cards.column_id required';
    }

    /**
     * Release 3b of the move from the status enum to column rows. The backfill
     * reads status one last time. A slug that a rename changed matches no
     * column, so such a row lands in the default column of its project.
     *
     * @contract-phase: the previous release stopped mapping status, so no deployed image reads or writes it, and every deployed image writes column_id
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE board_cards SET column_id = k.id FROM board_columns k WHERE k.project_id = board_cards.project_id AND k.slug = board_cards.status AND board_cards.column_id IS NULL');
        $this->addSql('UPDATE board_cards SET column_id = k.id FROM board_columns k WHERE k.project_id = board_cards.project_id AND k.is_default AND board_cards.column_id IS NULL');
        $this->addSql('ALTER TABLE board_cards ALTER column_id SET NOT NULL');
        $this->addSql('ALTER TABLE board_cards DROP status');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD status VARCHAR(20) DEFAULT NULL');
        // Cut to the old width. The previous image does not map status, and only the image before board columns reads the value.
        $this->addSql('UPDATE board_cards SET status = LEFT(k.slug, 20) FROM board_columns k WHERE k.id = board_cards.column_id');
        $this->addSql('ALTER TABLE board_cards ALTER column_id DROP NOT NULL');
    }
}
