<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919183457 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the OAuth device code table';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth2_device_code (identifier CHAR(80) NOT NULL, expiry TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_identifier VARCHAR(128) DEFAULT NULL, scopes TEXT DEFAULT NULL, revoked BOOLEAN NOT NULL, user_code VARCHAR(255) DEFAULT NULL, user_approved BOOLEAN NOT NULL, include_verification_uri_complete BOOLEAN NOT NULL, verification_uri VARCHAR(255) DEFAULT NULL, last_polled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, "interval" INT NOT NULL, client VARCHAR(32) NOT NULL, PRIMARY KEY (identifier))');
        $this->addSql('CREATE INDEX IDX_A816B6B0C7440455 ON oauth2_device_code (client)');
        $this->addSql('ALTER TABLE oauth2_device_code ADD CONSTRAINT FK_A816B6B0C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth2_device_code DROP CONSTRAINT FK_A816B6B0C7440455');
        $this->addSql('DROP TABLE oauth2_device_code');
    }
}
