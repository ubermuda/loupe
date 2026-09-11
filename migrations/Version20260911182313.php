<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A rename rather than the DROP + CREATE that migrate-diff generates: `sequence`
 * is a GENERATED ALWAYS AS IDENTITY column, and recreating the table would drop
 * both the rows and the identity the Mercure event ids come from.
 */
final class Version20260911182313 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename site_review_events to outbox_events and add the producer type column';
    }

    /**
     * @contract-phase: the table is empty on every instance and nothing has
     * written a row since the send step went away, so no deployed version reads
     * the old name with anything to find
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_events RENAME TO outbox_events');
        $this->addSql('ALTER SEQUENCE site_review_events_sequence_seq RENAME TO outbox_events_sequence_seq');
        $this->addSql('ALTER TABLE outbox_events RENAME CONSTRAINT site_review_events_pkey TO outbox_events_pkey');
        $this->addSql('ALTER TABLE outbox_events RENAME CONSTRAINT fk_8b527821166d1f9c TO fk_ca40e1d6166d1f9c');
        $this->addSql('ALTER INDEX idx_site_review_events_drain RENAME TO idx_outbox_events_drain');
        $this->addSql('ALTER INDEX uniq_8b5278215286d72b RENAME TO uniq_ca40e1d65286d72b');
        $this->addSql('ALTER INDEX idx_8b527821166d1f9c RENAME TO idx_ca40e1d6166d1f9c');

        // Added with a default so any existing row backfills to the only
        // producer that ever wrote one, then dropped so a future insert must
        // name its own type.
        $this->addSql("ALTER TABLE outbox_events ADD type VARCHAR(255) DEFAULT 'site_review.submitted' NOT NULL");
        $this->addSql('ALTER TABLE outbox_events ALTER COLUMN type DROP DEFAULT');
        $this->addSql('CREATE INDEX idx_outbox_events_type ON outbox_events (type)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_outbox_events_type');
        $this->addSql('ALTER TABLE outbox_events DROP type');

        $this->addSql('ALTER INDEX idx_ca40e1d6166d1f9c RENAME TO idx_8b527821166d1f9c');
        $this->addSql('ALTER INDEX uniq_ca40e1d65286d72b RENAME TO uniq_8b5278215286d72b');
        $this->addSql('ALTER INDEX idx_outbox_events_drain RENAME TO idx_site_review_events_drain');
        $this->addSql('ALTER TABLE outbox_events RENAME CONSTRAINT fk_ca40e1d6166d1f9c TO fk_8b527821166d1f9c');
        $this->addSql('ALTER TABLE outbox_events RENAME CONSTRAINT outbox_events_pkey TO site_review_events_pkey');
        $this->addSql('ALTER SEQUENCE outbox_events_sequence_seq RENAME TO site_review_events_sequence_seq');
        $this->addSql('ALTER TABLE outbox_events RENAME TO site_review_events');
    }
}
