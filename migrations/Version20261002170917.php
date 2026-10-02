<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002170917 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the workflow rule states table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_rule_states (id UUID NOT NULL, truth BOOLEAN DEFAULT false NOT NULL, attempts INT DEFAULT 0 NOT NULL, fires INT DEFAULT 0 NOT NULL, fingerprint VARCHAR(64) DEFAULT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_refusal VARCHAR(64) DEFAULT NULL, rule_id VARCHAR(100) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FC466C204ACC9A20 ON workflow_rule_states (card_id)');
        $this->addSql('CREATE INDEX IDX_FC466C20166D1F9C ON workflow_rule_states (project_id)');
        $this->addSql('CREATE INDEX idx_workflow_rule_states_due_at ON workflow_rule_states (due_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_workflow_rule_states_card_rule ON workflow_rule_states (card_id, rule_id)');
        $this->addSql('ALTER TABLE workflow_rule_states ADD CONSTRAINT FK_FC466C204ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_rule_states ADD CONSTRAINT FK_FC466C20166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_rule_states');
    }
}
