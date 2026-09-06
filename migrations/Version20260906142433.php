<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906142433 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board card tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_pull_requests (id UUID NOT NULL, url VARCHAR(512) NOT NULL, forge VARCHAR(20) NOT NULL, repository VARCHAR(255) DEFAULT NULL, number INT DEFAULT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A4295044ACC9A20 ON board_card_pull_requests (card_id)');
        $this->addSql('CREATE TABLE board_cards (id UUID NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, type VARCHAR(20) NOT NULL, priority INT NOT NULL, status VARCHAR(20) NOT NULL, origin VARCHAR(20) NOT NULL, position INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A67FBFD6166D1F9C ON board_cards (project_id)');
        // The board's only read query filters on project and status, then sorts
        // by priority and position. Doctrine indexes the join column alone.
        $this->addSql('CREATE INDEX idx_board_cards_board_order ON board_cards (project_id, status, priority, position)');
        $this->addSql('ALTER TABLE board_card_pull_requests ADD CONSTRAINT FK_A4295044ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_cards ADD CONSTRAINT FK_A67FBFD6166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_pull_requests DROP CONSTRAINT FK_A4295044ACC9A20');
        $this->addSql('ALTER TABLE board_cards DROP CONSTRAINT FK_A67FBFD6166D1F9C');
        $this->addSql('DROP TABLE board_card_pull_requests');
        $this->addSql('DROP TABLE board_cards');
    }
}
