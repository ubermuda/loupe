<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930010033 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the bridge.stop_sigkill_after_ms flag so an upgraded instance can change the wait before SIGKILL';
    }

    /**
     * The installer seeds this flag once, so an instance that upgrades has no
     * row. Seeded to 2500, the container parameter the code falls back to.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'bridge.stop_sigkill_after_ms', 'int', '2500', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'bridge.stop_sigkill_after_ms'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'bridge.stop_sigkill_after_ms'");
    }
}
