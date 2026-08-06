<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805200741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the site_review.push.enabled flag so existing instances keep publishing';
    }

    /**
     * Site-review push moved behind a flag, and every check treats a missing
     * flag as off. Without this row an instance that upgrades — the install
     * wizard having run long ago — would silently stop publishing reviews,
     * draining the outbox and issuing stream credentials.
     *
     * Seeded on rather than off, matching the installer: the flag's environment
     * prerequisite already holds it off where no hub is configured, so an
     * instance that was publishing before this migration keeps publishing, and
     * one that never configured Mercure is unaffected either way.
     *
     * The row also has to exist for the admin to offer a switch at all, so this
     * is what makes push turn-off-able on an upgraded instance rather than only
     * on a fresh install.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'site_review.push.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'site_review.push.enabled'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'site_review.push.enabled'");
    }
}
