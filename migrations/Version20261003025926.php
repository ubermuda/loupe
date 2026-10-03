<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003025926 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add wake_at to workflow rule states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD wake_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_workflow_rule_states_wake_at ON workflow_rule_states (wake_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_workflow_rule_states_wake_at');
        $this->addSql('ALTER TABLE workflow_rule_states DROP wake_at');
    }
}
