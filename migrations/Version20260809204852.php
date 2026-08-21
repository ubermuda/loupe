<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809204852 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the about.update_check.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag, but the installer only ever runs once, so
     * an instance that upgrades has no row — and the admin cannot offer a
     * switch for a flag it does not know about. This is what makes the update
     * check turn-on-able anywhere other than a fresh install.
     *
     * Seeded off, matching the installer: it is the only request the app makes
     * on its own initiative, so an upgrade must not start making it unasked.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'about.update_check.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'about.update_check.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'about.update_check.enabled'");
    }
}
