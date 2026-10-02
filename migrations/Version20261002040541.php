<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002040541 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the head branch, the merge and base change markers of a pull request, and the opt-ins for the pull request writes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD merge_pull_requests BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD change_base BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD epic_draft_switch BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD close_epic_pull_requests BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD head_branch VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD merge_requested_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD merge_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD base_change_requested_to VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD base_change_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP merge_pull_requests');
        $this->addSql('ALTER TABLE board_automation_settings DROP change_base');
        $this->addSql('ALTER TABLE board_automation_settings DROP epic_draft_switch');
        $this->addSql('ALTER TABLE board_automation_settings DROP close_epic_pull_requests');
        $this->addSql('ALTER TABLE forge_pull_requests DROP head_branch');
        $this->addSql('ALTER TABLE forge_pull_requests DROP merge_requested_sha');
        $this->addSql('ALTER TABLE forge_pull_requests DROP merge_requested_at');
        $this->addSql('ALTER TABLE forge_pull_requests DROP base_change_requested_to');
        $this->addSql('ALTER TABLE forge_pull_requests DROP base_change_requested_at');
    }
}
