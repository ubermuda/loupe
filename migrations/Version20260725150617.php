<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725150617 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create data_exports table';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE data_exports (id UUID NOT NULL, status VARCHAR(20) NOT NULL, download_token_hash VARCHAR(64) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_882542B1A76ED395 ON data_exports (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_data_exports_pending_user ON data_exports (user_id) WHERE ((status)::text = \'pending\'::text)');
        $this->addSql('ALTER TABLE data_exports ADD CONSTRAINT FK_882542B1A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE data_exports DROP CONSTRAINT FK_882542B1A76ED395');
        $this->addSql('DROP TABLE data_exports');
    }
}
