<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813095903 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the landing.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row — and the admin cannot offer a switch for a flag
     * it does not know about.
     *
     * Seeded off, matching the installer: the page advertises a hosted plan with
     * a price on it, so serving it is a decision only the instance selling that
     * plan should make. While it is off, anonymous visitors reach `/` exactly as
     * they did before the page existed.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'landing.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'landing.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'landing.enabled'");
    }
}
