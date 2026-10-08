<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008014934 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that ties a workflow inbox item to its card and rule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_workflow_asks (id UUID NOT NULL, card_id UUID NOT NULL, rule_id VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C89E0D43126F525E ON inbox_workflow_asks (item_id)');
        $this->addSql('CREATE INDEX idx_inbox_workflow_asks_card ON inbox_workflow_asks (card_id)');
        $this->addSql('ALTER TABLE inbox_workflow_asks ADD CONSTRAINT FK_C89E0D43126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_workflow_asks DROP CONSTRAINT FK_C89E0D43126F525E');
        $this->addSql('DROP TABLE inbox_workflow_asks');
    }
}
