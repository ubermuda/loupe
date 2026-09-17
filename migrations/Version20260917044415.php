<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917044415 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add optional project descriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD description TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP description');
    }
}
