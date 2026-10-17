<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017174902 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the inbox.session_prompt_max_length flag so an upgraded instance can change the limit';
    }

    /**
     * The installer seeds this flag, but it only ever runs once, so an instance
     * that upgrades has no row, and the admin cannot offer a field for it.
     *
     * Seeded to 2000, the coded fallback.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'inbox.session_prompt_max_length', 'int', '2000', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'inbox.session_prompt_max_length'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'inbox.session_prompt_max_length'");
    }
}
