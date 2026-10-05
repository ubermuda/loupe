<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005120708 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the context of a work request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD context JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP context');
    }
}
