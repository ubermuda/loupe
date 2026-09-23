<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923133401 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create forge_repositories, github_hooks and github_installations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forge_repositories (id UUID NOT NULL, last_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, forge VARCHAR(50) NOT NULL, external_id VARCHAR(255) NOT NULL, path VARCHAR(255) NOT NULL, source VARCHAR(20) NOT NULL, source_ref VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E041F9EE166D1F9C ON forge_repositories (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_forge_repositories_forge_external_id_project ON forge_repositories (forge, external_id, project_id)');
        $this->addSql('CREATE TABLE github_hooks (id UUID NOT NULL, last_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_refused_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_refused_reason VARCHAR(50) DEFAULT NULL, hook_key VARCHAR(26) NOT NULL, secret TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D757503FEA2DCEF7 ON github_hooks (hook_key)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D757503F166D1F9C ON github_hooks (project_id)');
        $this->addSql('CREATE TABLE github_installations (id UUID NOT NULL, suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, installation_id BIGINT NOT NULL, account_login VARCHAR(255) NOT NULL, repository_selection VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B577C4D3167B88B4 ON github_installations (installation_id)');
        $this->addSql('CREATE INDEX IDX_B577C4D3166D1F9C ON github_installations (project_id)');
        $this->addSql('ALTER TABLE forge_repositories ADD CONSTRAINT FK_E041F9EE166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE github_hooks ADD CONSTRAINT FK_D757503F166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE github_installations ADD CONSTRAINT FK_B577C4D3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_repositories DROP CONSTRAINT FK_E041F9EE166D1F9C');
        $this->addSql('ALTER TABLE github_hooks DROP CONSTRAINT FK_D757503F166D1F9C');
        $this->addSql('ALTER TABLE github_installations DROP CONSTRAINT FK_B577C4D3166D1F9C');
        $this->addSql('DROP TABLE forge_repositories');
        $this->addSql('DROP TABLE github_hooks');
        $this->addSql('DROP TABLE github_installations');
    }
}
