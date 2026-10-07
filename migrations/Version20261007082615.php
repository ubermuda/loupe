<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007082615 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the agent GitHub login to projects and the push login to bridges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD agent_github_login VARCHAR(39) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD push_login VARCHAR(39) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP agent_github_login');
        $this->addSql('ALTER TABLE bridges DROP push_login');
    }
}
