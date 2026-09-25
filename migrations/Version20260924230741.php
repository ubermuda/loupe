<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924230741 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the hook report a bridge heartbeat carries to bridges';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD hooks JSON DEFAULT \'[]\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP hooks');
    }
}
