<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002040548 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create workflow_bindings and workflow_slot_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_bindings (id UUID NOT NULL, template_key VARCHAR(100) NOT NULL, template_version INT NOT NULL, definition JSONB NOT NULL, bound_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9B3AB261166D1F9C ON workflow_bindings (project_id)');
        $this->addSql('CREATE TABLE workflow_slot_links (id UUID NOT NULL, slot_key VARCHAR(100) NOT NULL, project_id UUID NOT NULL, column_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_22185060166D1F9C ON workflow_slot_links (project_id)');
        $this->addSql('CREATE INDEX IDX_22185060BE8E8ED5 ON workflow_slot_links (column_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_slot_links_project_slot ON workflow_slot_links (project_id, slot_key)');
        $this->addSql('ALTER TABLE workflow_bindings ADD CONSTRAINT FK_9B3AB261166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_slot_links ADD CONSTRAINT FK_22185060166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_slot_links ADD CONSTRAINT FK_22185060BE8E8ED5 FOREIGN KEY (column_id) REFERENCES board_columns (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_slot_links');
        $this->addSql('DROP TABLE workflow_bindings');
    }
}
