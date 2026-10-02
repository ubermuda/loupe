<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002205329 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add last_refusal_at to workflow rule states';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD last_refusal_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP last_refusal_at');
    }
}
