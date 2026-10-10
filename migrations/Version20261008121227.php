<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008121227 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create github_user_connections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE github_user_connections (id UUID NOT NULL, expired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, github_user_id BIGINT NOT NULL, login VARCHAR(255) NOT NULL, access_token TEXT NOT NULL, refresh_token TEXT NOT NULL, access_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, refresh_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, connected_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B5FF3C81A76ED395 ON github_user_connections (user_id)');
        $this->addSql('ALTER TABLE github_user_connections ADD CONSTRAINT FK_B5FF3C81A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_user_connections DROP CONSTRAINT FK_B5FF3C81A76ED395');
        $this->addSql('DROP TABLE github_user_connections');
    }
}
