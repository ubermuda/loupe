<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905221842 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the site_review.drawing.enabled flag so upgraded instances can switch it off';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a switch for a flag
     * it does not know about.
     *
     * Seeded on, matching the installer and the default every call site passes.
     * Drawing is additive and destroys nothing, so an operator who wants it off
     * turns it off rather than having to find a switch to turn it on.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'site_review.drawing.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'site_review.drawing.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'site_review.drawing.enabled'");
    }
}
