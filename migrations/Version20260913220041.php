<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913220041 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the bridge.run_retention_days flag so an upgraded instance can change the window';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a field for a flag it
     * does not know about.
     *
     * Seeded to 180, which is the container parameter the code falls back to.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'bridge.run_retention_days', 'int', '180', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'bridge.run_retention_days'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'bridge.run_retention_days'");
    }
}
