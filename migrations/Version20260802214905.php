<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802214905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add archived_at to documents and description to document_versions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_versions ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE documents ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document_versions DROP description');
        $this->addSql('ALTER TABLE documents DROP archived_at');
    }
}
