<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827011856 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Collapse duplicate verdicts and make reviews.version_id unique';
    }

    public function up(Schema $schema): void
    {
        // A double-clicked submit could write two verdicts on one version. Both
        // carry the same second in submitted_at, so neither is demonstrably the
        // later one and the pair has no ordering to preserve — keep one per version
        // and let the index below make the state unreachable from here on.
        $this->addSql(<<<'SQL'
            DELETE FROM reviews WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (
                        PARTITION BY version_id ORDER BY submitted_at DESC, id DESC
                    ) AS rank FROM reviews
                ) ranked WHERE ranked.rank > 1
            )
            SQL);

        $this->addSql('DROP INDEX idx_6970eb0f4bbc2705');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6970EB0F4BBC2705 ON reviews (version_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The deleted duplicates are not restored — they are gone for good.
        $this->addSql('DROP INDEX UNIQ_6970EB0F4BBC2705');
        $this->addSql('CREATE INDEX idx_6970eb0f4bbc2705 ON reviews (version_id)');
    }
}
