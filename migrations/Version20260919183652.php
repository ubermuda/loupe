<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919183652 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the sites a project lets its sign-in widget run on';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD allowed_origins JSONB DEFAULT \'[]\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP allowed_origins');
    }
}
