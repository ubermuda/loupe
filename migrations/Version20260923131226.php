<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923131226 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create forge_repositories';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forge_repositories (id UUID NOT NULL, last_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, forge VARCHAR(50) NOT NULL, external_id VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E041F9EE166D1F9C ON forge_repositories (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_forge_repositories_forge_external_id ON forge_repositories (forge, external_id)');
        $this->addSql('ALTER TABLE forge_repositories ADD CONSTRAINT FK_E041F9EE166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories DROP CONSTRAINT FK_E041F9EE166D1F9C');
        $this->addSql('DROP TABLE forge_repositories');
    }
}
