<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727012747 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add unique constraint on document_versions (document_id, version_number)';
    }

    public function up(Schema $schema): void
    {
        // Renumber first: the race this index closes may already have produced
        // duplicate (document_id, version_number) rows, and CREATE UNIQUE INDEX
        // would abort the deployment on exactly the databases that need it.
        // Only documents that actually collide are touched, and the existing
        // order is preserved (version_number, then created_at, then id as a
        // deterministic tie-break) so the highest version stays the current one.
        $this->addSql(<<<'SQL'
            WITH ranked AS (
                SELECT id, ROW_NUMBER() OVER (
                    PARTITION BY document_id
                    ORDER BY version_number, created_at, id
                ) AS new_number
                FROM document_versions
                WHERE document_id IN (
                    SELECT document_id
                    FROM document_versions
                    GROUP BY document_id, version_number
                    HAVING COUNT(*) > 1
                )
            )
            UPDATE document_versions
            SET version_number = ranked.new_number
            FROM ranked
            WHERE document_versions.id = ranked.id
              AND document_versions.version_number <> ranked.new_number
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_document_version_number ON document_versions (document_id, version_number)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_document_version_number');
    }
}
