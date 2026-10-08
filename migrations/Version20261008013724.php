<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008013724 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the account checks each bridge reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD accounts JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD accounts_reported_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP accounts');
        $this->addSql('ALTER TABLE bridges DROP accounts_reported_at');
    }
}
