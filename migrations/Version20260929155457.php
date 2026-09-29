<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929155457 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create bridge_experiment_pins and add the experiment columns to bridge_worker_runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_experiment_pins (id UUID NOT NULL, card_id UUID NOT NULL, experiment VARCHAR(64) NOT NULL, variant VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_69C3C5C9166D1F9C ON bridge_experiment_pins (project_id)');
        $this->addSql('CREATE INDEX idx_bridge_experiment_pins_updated ON bridge_experiment_pins (updated_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_experiment_pin ON bridge_experiment_pins (project_id, card_id, experiment)');
        $this->addSql('ALTER TABLE bridge_experiment_pins ADD CONSTRAINT FK_69C3C5C9166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD experiment VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD variant VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD requested_model VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD switched_from VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_pins DROP CONSTRAINT FK_69C3C5C9166D1F9C');
        $this->addSql('DROP TABLE bridge_experiment_pins');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP experiment');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP variant');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP requested_model');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP switched_from');
    }
}
