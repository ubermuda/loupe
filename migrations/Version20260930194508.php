<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930194508 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add install_method to bridges';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges ADD install_method VARCHAR(20) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP install_method');
    }
}
