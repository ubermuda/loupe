<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913202037 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_bridge_rule_reports';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_bridge_rule_reports (id UUID NOT NULL, bridge_id UUID NOT NULL, rules JSON NOT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_bridge_rule_reports_project_bridge ON board_bridge_rule_reports (project_id, bridge_id)');
        $this->addSql('ALTER TABLE board_bridge_rule_reports ADD CONSTRAINT FK_61AC487A166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_bridge_rule_reports DROP CONSTRAINT FK_61AC487A166D1F9C');
        $this->addSql('DROP TABLE board_bridge_rule_reports');
    }
}
