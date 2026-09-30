<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930145009 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create bridge_experiment_definitions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_experiment_definitions (id UUID NOT NULL, experiment VARCHAR(64) NOT NULL, weights JSON NOT NULL, reported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F0100581166D1F9C ON bridge_experiment_definitions (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_experiment_definition ON bridge_experiment_definitions (project_id, experiment)');
        $this->addSql('ALTER TABLE bridge_experiment_definitions ADD CONSTRAINT FK_F0100581166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions DROP CONSTRAINT FK_F0100581166D1F9C');
        $this->addSql('DROP TABLE bridge_experiment_definitions');
    }
}
