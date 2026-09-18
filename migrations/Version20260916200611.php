<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916200611 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add retained thread deletion state to comments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE comments ADD deletion_sequence INT DEFAULT 0 NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments DROP deleted_at');
        $this->addSql('ALTER TABLE comments DROP deletion_sequence');
    }
}
