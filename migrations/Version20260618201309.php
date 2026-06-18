<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618201309 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_tokens (id UUID NOT NULL, label VARCHAR(100) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_2CAD560EB3BC57DA ON api_tokens (token_hash)');
        $this->addSql('CREATE INDEX IDX_2CAD560E7E3C61F9 ON api_tokens (owner_id)');
        $this->addSql('ALTER TABLE api_tokens ADD CONSTRAINT FK_2CAD560E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_tokens DROP CONSTRAINT FK_2CAD560E7E3C61F9');
        $this->addSql('DROP TABLE api_tokens');
    }
}
