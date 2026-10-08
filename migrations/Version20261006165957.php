<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006165957 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the insights.subcommand_programs flag so upgraded instances can edit it';
    }

    /** The installer seeds this flag once, so an instance that upgrades has no row. */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'insights.subcommand_programs', 'string', '"git,just,npm,pnpm,yarn,cargo,go,docker,gh,composer,make,pip,uv"', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'insights.subcommand_programs'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'insights.subcommand_programs'");
    }
}
