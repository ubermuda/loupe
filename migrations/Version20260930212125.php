<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930212125 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the approval and branch facts to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD approval_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD covered_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD default_branch VARCHAR(255) DEFAULT NULL');
        $this->addSql("ALTER TABLE forge_pull_requests ADD head_parents JSON DEFAULT '[]' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP approved_at');
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_sha');
        $this->addSql('ALTER TABLE forge_pull_requests DROP approval_id');
        $this->addSql('ALTER TABLE forge_pull_requests DROP covered_sha');
        $this->addSql('ALTER TABLE forge_pull_requests DROP default_branch');
        $this->addSql('ALTER TABLE forge_pull_requests DROP head_parents');
    }
}
