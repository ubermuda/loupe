<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827014418 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add a per-version sequence to reviews so the verdict log has a total order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews ADD sequence INT DEFAULT NULL');

        // Existing rows get the best order the data supports. submitted_at is only
        // second-precision, so rows written in the same second fall back to the id —
        // arbitrary, but stable, and it only decides between rows nothing else can
        // separate. From here on the column is assigned, not inferred.
        $this->addSql(<<<'SQL'
            UPDATE reviews SET sequence = ranked.position
            FROM (
                SELECT id, row_number() OVER (
                    PARTITION BY version_id ORDER BY submitted_at, id
                ) AS position FROM reviews
            ) ranked
            WHERE reviews.id = ranked.id
            SQL);

        $this->addSql('ALTER TABLE reviews ALTER COLUMN sequence SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_reviews_version_sequence ON reviews (version_id, sequence)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_reviews_version_sequence');
        $this->addSql('ALTER TABLE reviews DROP sequence');
    }
}
