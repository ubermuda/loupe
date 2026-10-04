<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002210005 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create workflow_pending_baselines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workflow_pending_baselines (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_111053F04ACC9A20 ON workflow_pending_baselines (card_id)');
        $this->addSql('CREATE INDEX IDX_111053F0166D1F9C ON workflow_pending_baselines (project_id)');
        $this->addSql('ALTER TABLE workflow_pending_baselines ADD CONSTRAINT FK_111053F04ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workflow_pending_baselines ADD CONSTRAINT FK_111053F0166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE workflow_pending_baselines');
    }
}
