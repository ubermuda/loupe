<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913164721 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the live_updates.enabled flag from agent.push.enabled, which gated live updates before';
    }

    /**
     * The installer seeds this flag once, so an upgraded instance has no row.
     * It takes the value of agent.push.enabled, the flag that switched live
     * updates until now, so a deploy changes nothing. A row whose value is not
     * true reads as off, NULL included. With no such row, it is on.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'live_updates.enabled', 'bool',
                COALESCE(
                    (SELECT CASE WHEN value::text = 'true' THEN 'true' ELSE 'false' END FROM feature_flag WHERE name = 'agent.push.enabled'),
                    'true'
                )::json,
                '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'live_updates.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'live_updates.enabled'");
    }
}
