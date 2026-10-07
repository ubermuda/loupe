<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006200235 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the app prompt text to work requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD prompt TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP prompt');
    }
}
