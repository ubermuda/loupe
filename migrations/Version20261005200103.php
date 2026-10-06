<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005200103 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Delete the board.enabled flag, because the board is always on';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'board.enabled'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'board.enabled', 'bool', 'true', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'board.enabled'
            )
            SQL);
    }
}
