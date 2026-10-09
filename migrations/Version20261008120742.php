<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008120742 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the author id, login and read flag of a pull request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD author_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD author_login VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD author_read BOOLEAN DEFAULT false NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP author_id');
        $this->addSql('ALTER TABLE forge_pull_requests DROP author_login');
        $this->addSql('ALTER TABLE forge_pull_requests DROP author_read');
    }
}
