<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727012747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique constraint on document_versions (document_id, version_number)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_document_version_number ON document_versions (document_id, version_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_document_version_number');
    }
}
