<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917023716 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Deduplicate widget comment deliveries within each project';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_comments ADD delivery_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE site_review_comments ADD delivery_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7246C1CA166D1F9C12136921 ON site_review_comments (project_id, delivery_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_7246C1CA166D1F9C12136921');
        $this->addSql('ALTER TABLE site_review_comments DROP delivery_id');
        $this->addSql('ALTER TABLE site_review_comments DROP delivery_hash');
    }
}
