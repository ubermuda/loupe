<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914151507 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the inbox.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer seeds this flag, but it runs only once, so an instance that
     * upgrades has no row and the admin offers no switch. Seeded off, as the
     * installer seeds it.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'inbox.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'inbox.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'inbox.enabled'");
    }
}
