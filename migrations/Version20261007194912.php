<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007194912 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the subcommand programs override to the insights project settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_project_settings ADD subcommand_programs JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE insights_project_settings DROP subcommand_programs');
    }
}
