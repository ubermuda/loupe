<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804003254 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add archive_reason to documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents ADD archive_reason TEXT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE documents DROP archive_reason');
    }
}
