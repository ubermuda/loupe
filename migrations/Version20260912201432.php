<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260912201432 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_columns and a nullable board_cards.column_id';
    }

    /**
     * Release 1 of the move from the status enum to column rows, and it only
     * expands. Schema only, so the lock on board_cards ends before the backfill.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_columns (id UUID NOT NULL, label VARCHAR(100) NOT NULL, slug TEXT NOT NULL, position INT NOT NULL, terminal BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E4D0E8B8166D1F9C ON board_columns (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_columns_project_slug ON board_columns (project_id, slug)');
        $this->addSql('ALTER TABLE board_columns ADD CONSTRAINT FK_E4D0E8B8166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_cards ADD column_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD CONSTRAINT FK_A67FBFD6BE8E8ED5 FOREIGN KEY (column_id) REFERENCES board_columns (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP CONSTRAINT FK_A67FBFD6BE8E8ED5');
        $this->addSql('ALTER TABLE board_cards DROP column_id');
        $this->addSql('ALTER TABLE board_columns DROP CONSTRAINT FK_E4D0E8B8166D1F9C');
        $this->addSql('DROP TABLE board_columns');
    }
}
