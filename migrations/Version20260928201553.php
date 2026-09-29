<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928201553 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Seed the review.mermaid.enabled flag so upgraded instances can switch it on';
    }

    /**
     * The installer runs once, so an upgraded instance has no row for this flag.
     * Seeded off, matching the installer: diagrams load a script from a CDN.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO feature_flag (name, type, value, tags, options)
            SELECT 'review.mermaid.enabled', 'bool', 'false', '[]', NULL
            WHERE NOT EXISTS (
                SELECT 1 FROM feature_flag WHERE name = 'review.mermaid.enabled'
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM feature_flag WHERE name = 'review.mermaid.enabled'");
    }
}
