<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924223410 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store the CLI update state a bridge reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD update_state VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD update_version VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP update_state');
        $this->addSql('ALTER TABLE bridges DROP update_version');
    }
}
