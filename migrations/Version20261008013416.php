<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008013416 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the inbox item id of an ask to the workflow rule states';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states ADD ask_item_id UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_rule_states DROP ask_item_id');
    }
}
