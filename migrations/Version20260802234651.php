<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802234651 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace comments.resolved with a status column (pending/addressed/resolved). No deployment holds document comments yet, so existing rows are given the default instead of being mapped from resolved.';
    }

    public function up(Schema $schema): void
    {
        // The column is added with a default so the NOT NULL add succeeds on a
        // populated table, then the default is dropped to match the entity.
        $this->addSql("ALTER TABLE comments ADD status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        $this->addSql('ALTER TABLE comments ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE comments DROP resolved');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments ADD resolved BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE comments ALTER resolved DROP DEFAULT');
        $this->addSql('ALTER TABLE comments DROP status');
    }
}
