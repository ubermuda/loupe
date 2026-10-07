<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007145432 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the discovery_runs table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discovery_runs (id UUID NOT NULL, state VARCHAR(20) NOT NULL, failure_reason VARCHAR(1000) DEFAULT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, report_document_id UUID DEFAULT NULL, project_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_20F720117227D33F ON discovery_runs (report_document_id)');
        $this->addSql('CREATE INDEX IDX_20F72011166D1F9C ON discovery_runs (project_id)');
        $this->addSql('CREATE INDEX IDX_20F720114ACC9A20 ON discovery_runs (card_id)');
        $this->addSql('CREATE INDEX idx_discovery_runs_card_created ON discovery_runs (card_id, created_at)');
        $this->addSql('CREATE INDEX idx_discovery_runs_project_created ON discovery_runs (project_id, created_at)');
        $this->addSql('ALTER TABLE discovery_runs ADD CONSTRAINT FK_20F720117227D33F FOREIGN KEY (report_document_id) REFERENCES documents (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE discovery_runs ADD CONSTRAINT FK_20F72011166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE discovery_runs ADD CONSTRAINT FK_20F720114ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE discovery_runs');
    }
}
