<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002181341 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the held and requested names to bridges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD name VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD requested_name VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridges_owner_name ON bridges (owner_id, name) WHERE (name IS NOT NULL)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bridges_owner_name');
        $this->addSql('ALTER TABLE bridges DROP name');
        $this->addSql('ALTER TABLE bridges DROP requested_name');
    }
}
