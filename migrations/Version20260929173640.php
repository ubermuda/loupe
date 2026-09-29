<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929173640 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the pull request waits: the pull request and head commit of a wait, and two wait switches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_card_waits ADD pull_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_card_waits ADD head_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_project_settings ADD pull_request_ready BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE inbox_project_settings ADD pull_request_fix_stopped BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_project_settings DROP pull_request_fix_stopped');
        $this->addSql('ALTER TABLE inbox_project_settings DROP pull_request_ready');
        $this->addSql('ALTER TABLE inbox_card_waits DROP head_sha');
        $this->addSql('ALTER TABLE inbox_card_waits DROP pull_request_id');
    }
}
