<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928124059 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board automation settings, with one row for each project';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_automation_settings (id UUID NOT NULL, enabled BOOLEAN NOT NULL, merge_strategy VARCHAR(20) NOT NULL, fix_strategy VARCHAR(20) NOT NULL, loop_limit INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B8DF7A0F166D1F9C ON board_automation_settings (project_id)');
        $this->addSql('ALTER TABLE board_automation_settings ADD CONSTRAINT FK_B8DF7A0F166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_automation_settings');
    }
}
