<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929132110 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the inbox wait switches of a project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_project_settings (id UUID NOT NULL, document_in_review BOOLEAN DEFAULT true NOT NULL, run_blocked BOOLEAN DEFAULT true NOT NULL, run_gave_up BOOLEAN DEFAULT true NOT NULL, run_waiting_for_person BOOLEAN DEFAULT true NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5FB0398D166D1F9C ON inbox_project_settings (project_id)');
        $this->addSql('ALTER TABLE inbox_project_settings ADD CONSTRAINT FK_5FB0398D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_project_settings DROP CONSTRAINT FK_5FB0398D166D1F9C');
        $this->addSql('DROP TABLE inbox_project_settings');
    }
}
