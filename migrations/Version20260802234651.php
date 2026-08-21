<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802234651 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Replace comments.resolved with a status column (pending/addressed/resolved), mapping existing resolved rows onto the resolved case so no resolution state is lost.';
    }

    public function up(Schema $schema): void
    {
        // The column is added with a default so the NOT NULL add succeeds on a
        // populated table, then the default is dropped to match the entity.
        $this->addSql("ALTER TABLE comments ADD status VARCHAR(20) DEFAULT 'pending' NOT NULL");
        // Must run before the source column is dropped: resolved is the only
        // record of which threads are closed, and dropping it is irreversible.
        $this->addSql("UPDATE comments SET status = 'resolved' WHERE resolved = true");
        $this->addSql('ALTER TABLE comments ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE comments DROP resolved');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comments ADD resolved BOOLEAN DEFAULT false NOT NULL');
        // Addressed is not closed, so it reverts to false along with pending.
        $this->addSql("UPDATE comments SET resolved = (status = 'resolved')");
        $this->addSql('ALTER TABLE comments ALTER resolved DROP DEFAULT');
        $this->addSql('ALTER TABLE comments DROP status');
    }
}
