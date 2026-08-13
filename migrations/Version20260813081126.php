<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813081126 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the review.highlights.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row — and the admin cannot offer a switch for a flag
     * it does not know about.
     *
     * Seeded off, matching the installer, even though highlighting worked on
     * these instances until now: it steers where a reviewer looks first, and an
     * upgrade is where the operator gets asked rather than told.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'review.highlights.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'review.highlights.enabled'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'review.highlights.enabled'");
    }
}
