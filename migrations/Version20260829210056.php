<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829210056 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create audit_log, the durable trail the Doctrine audit sink drains into';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE audit_log (id UUID NOT NULL, operation VARCHAR(100) NOT NULL, outcome VARCHAR(20) NOT NULL, category VARCHAR(20) NOT NULL, channel VARCHAR(20) NOT NULL, occurred_at TIMESTAMP(6) WITHOUT TIME ZONE NOT NULL, context JSONB DEFAULT \'{}\' NOT NULL, actor_label VARCHAR(255) DEFAULT NULL, subject_type VARCHAR(50) DEFAULT NULL, subject_id VARCHAR(64) DEFAULT NULL, actor_id UUID DEFAULT NULL, credential_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F6E1C0F510DAF24A ON audit_log (actor_id)');
        $this->addSql('CREATE INDEX IDX_F6E1C0F52558A7A5 ON audit_log (credential_id)');
        $this->addSql('CREATE INDEX idx_audit_log_subject ON audit_log (subject_type, subject_id)');
        $this->addSql('CREATE INDEX idx_audit_log_operation_occurred_at ON audit_log (operation, occurred_at)');
        $this->addSql('CREATE INDEX idx_audit_log_occurred_at ON audit_log (occurred_at)');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F510DAF24A FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F52558A7A5 FOREIGN KEY (credential_id) REFERENCES api_tokens (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F510DAF24A');
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT FK_F6E1C0F52558A7A5');
        $this->addSql('DROP TABLE audit_log');
    }
}
