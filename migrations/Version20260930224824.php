<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930224824 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create beta_invites';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE beta_invites (id UUID NOT NULL, redeemed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, token VARCHAR(64) NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, redeemed_by_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3891EAFF5F37A13B ON beta_invites (token)');
        $this->addSql('CREATE INDEX IDX_3891EAFF2FBC08BA ON beta_invites (redeemed_by_id)');
        $this->addSql('CREATE INDEX IDX_3891EAFFB03A8386 ON beta_invites (created_by_id)');
        $this->addSql('ALTER TABLE beta_invites ADD CONSTRAINT FK_3891EAFF2FBC08BA FOREIGN KEY (redeemed_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE beta_invites ADD CONSTRAINT FK_3891EAFFB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE beta_invites DROP CONSTRAINT FK_3891EAFF2FBC08BA');
        $this->addSql('ALTER TABLE beta_invites DROP CONSTRAINT FK_3891EAFFB03A8386');
        $this->addSql('DROP TABLE beta_invites');
    }
}
