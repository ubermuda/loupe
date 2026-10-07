<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006171900 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the epic branch pattern and the epic pull request opt-in to the board settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD open_epic_pull_requests BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD epic_branch_pattern VARCHAR(255) DEFAULT \'epic/{number}\'');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP open_epic_pull_requests');
        $this->addSql('ALTER TABLE board_automation_settings DROP epic_branch_pattern');
    }
}
