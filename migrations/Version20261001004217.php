<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001004217 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the sync of a pull request that is behind its base';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD sync_behind BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD sync_from_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD sync_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD sync_failed_reason VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD synced_sha VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP sync_behind');
        $this->addSql('ALTER TABLE forge_pull_requests DROP sync_from_sha');
        $this->addSql('ALTER TABLE forge_pull_requests DROP sync_requested_at');
        $this->addSql('ALTER TABLE forge_pull_requests DROP sync_failed_reason');
        $this->addSql('ALTER TABLE forge_pull_requests DROP synced_sha');
    }
}
