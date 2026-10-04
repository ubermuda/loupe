<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003042040 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record why a bridge command exists';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD cause VARCHAR(20) DEFAULT \'person\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands DROP cause');
    }
}
