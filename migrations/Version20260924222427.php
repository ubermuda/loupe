<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924222427 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Turn the board.enabled flag on, because site review writes its notes to cards';
    }

    /**
     * Upgrades an instance that has the row, and seeds one on an instance that
     * never ran the installer step that creates it.
     */
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET value = 'true' WHERE name = 'board.enabled'");
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'board.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'board.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE feature_flag SET value = 'false' WHERE name = 'board.enabled'");
    }
}
