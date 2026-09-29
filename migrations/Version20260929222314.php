<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929222314 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_pull_request_comments and add comment_on_fix_queued to board automation settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_pull_request_comments (id UUID NOT NULL, state VARCHAR(20) NOT NULL, attempts INT DEFAULT 0 NOT NULL, cause VARCHAR(100) DEFAULT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, run_id UUID NOT NULL, card_id UUID NOT NULL, forge VARCHAR(50) NOT NULL, repository VARCHAR(255) NOT NULL, number INT NOT NULL, head_sha VARCHAR(64) DEFAULT NULL, reason VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_128174884E3FEC4 ON board_pull_request_comments (run_id)');
        $this->addSql('CREATE INDEX IDX_1281748166D1F9C ON board_pull_request_comments (project_id)');
        $this->addSql('ALTER TABLE board_pull_request_comments ADD CONSTRAINT FK_1281748166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_automation_settings ADD comment_on_fix_queued BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_pull_request_comments DROP CONSTRAINT FK_1281748166D1F9C');
        $this->addSql('DROP TABLE board_pull_request_comments');
        $this->addSql('ALTER TABLE board_automation_settings DROP comment_on_fix_queued');
    }
}
