<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001203814 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_pull_request_notices and add comment_on_stale_approval to board automation settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_pull_request_notices (id UUID NOT NULL, state VARCHAR(20) NOT NULL, attempts INT DEFAULT 0 NOT NULL, cause VARCHAR(100) DEFAULT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, forge_pull_request_id UUID NOT NULL, notice_key VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6540DAA166D1F9C ON board_pull_request_notices (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_pull_request_notices_pull_request_key ON board_pull_request_notices (forge_pull_request_id, notice_key)');
        $this->addSql('ALTER TABLE board_pull_request_notices ADD CONSTRAINT FK_6540DAA166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_automation_settings ADD comment_on_stale_approval BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_pull_request_notices DROP CONSTRAINT FK_6540DAA166D1F9C');
        $this->addSql('DROP TABLE board_pull_request_notices');
        $this->addSql('ALTER TABLE board_automation_settings DROP comment_on_stale_approval');
    }
}
