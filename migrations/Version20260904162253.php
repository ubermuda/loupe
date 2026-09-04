<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904162253 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the section_approvals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE section_approvals (id UUID NOT NULL, heading_id VARCHAR(255) NOT NULL, content_hash VARCHAR(64) NOT NULL, version_number INT NOT NULL, approved_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, document_id UUID NOT NULL, approver_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D5E9A65BC33F7837 ON section_approvals (document_id)');
        $this->addSql('CREATE INDEX IDX_D5E9A65BBB23766C ON section_approvals (approver_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_section_approval_heading ON section_approvals (document_id, heading_id, approver_id)');
        $this->addSql('ALTER TABLE section_approvals ADD CONSTRAINT FK_D5E9A65BC33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE section_approvals ADD CONSTRAINT FK_D5E9A65BBB23766C FOREIGN KEY (approver_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE section_approvals DROP CONSTRAINT FK_D5E9A65BC33F7837');
        $this->addSql('ALTER TABLE section_approvals DROP CONSTRAINT FK_D5E9A65BBB23766C');
        $this->addSql('DROP TABLE section_approvals');
    }
}
