<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725152835 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create connected_accounts for social login identities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connected_accounts (id UUID NOT NULL, provider VARCHAR(20) NOT NULL, provider_user_id VARCHAR(191) NOT NULL, email VARCHAR(180) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E3A2453A76ED395 ON connected_accounts (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_provider_subject ON connected_accounts (provider, provider_user_id)');
        $this->addSql('ALTER TABLE connected_accounts ADD CONSTRAINT FK_E3A2453A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connected_accounts DROP CONSTRAINT FK_E3A2453A76ED395');
        $this->addSql('DROP TABLE connected_accounts');
    }
}
