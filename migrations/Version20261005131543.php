<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005131543 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the work request context of a bridge command';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD context JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands DROP context');
    }
}
