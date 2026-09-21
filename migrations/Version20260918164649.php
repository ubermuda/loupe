<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918164649 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the search.topbar.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag once, so an instance that upgrades has no
     * row. Seeded off, matching the installer.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'search.topbar.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'search.topbar.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'search.topbar.enabled'");
    }
}
