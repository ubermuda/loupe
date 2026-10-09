<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007145607 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the insights.default_analysis_model flag so upgraded instances can edit it';
    }

    /** The installer seeds this flag once, so an instance that upgrades has no row. */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'insights.default_analysis_model', 'string', '"sonnet"', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'insights.default_analysis_model'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'insights.default_analysis_model'");
    }
}
