<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007150100 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the last work request and the repaired flag to the workflow rule states';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD work_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE workflow_rule_states ADD repaired BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP work_request_id');
        $this->addSql('ALTER TABLE workflow_rule_states DROP repaired');
    }
}
