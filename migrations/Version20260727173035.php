<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260727173035 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the SiteReview entity: comments and events key on project directly, comment status gains Draft, site_review_reviews is dropped.';
    }

    public function up(Schema $schema): void
    {
        // --- site_review_comments: review_id -> project_id -------------------------------
        $this->addSql('ALTER TABLE site_review_comments ADD project_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET project_id = r.project_id
            FROM site_review_reviews r
            WHERE c.review_id = r.id
            SQL);
        // Comments whose review was still in-progress become Draft; comments on an
        // already-submitted review (Pending/Addressed/Resolved) keep their status.
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET status = 'draft'
            FROM site_review_reviews r
            WHERE c.review_id = r.id AND r.status = 'in-progress'
            SQL);
        // position was scoped per review; renumber it per project (chronological,
        // id as a deterministic tiebreak) now that comments from multiple former
        // reviews share one flat, position-ordered list.
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET position = renumbered.rn - 1
            FROM (
                SELECT id, ROW_NUMBER() OVER (PARTITION BY project_id ORDER BY created_at, id) AS rn
                FROM site_review_comments
            ) renumbered
            WHERE c.id = renumbered.id
            SQL);
        $this->addSql('ALTER TABLE site_review_comments ALTER COLUMN project_id SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments DROP CONSTRAINT fk_7246c1ca3e2e969b');
        $this->addSql('DROP INDEX idx_7246c1ca3e2e969b');
        $this->addSql('ALTER TABLE site_review_comments DROP COLUMN review_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE site_review_comments
            ADD CONSTRAINT FK_7246C1CA166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE
            SQL);
        $this->addSql('CREATE INDEX IDX_7246C1CA166D1F9C ON site_review_comments (project_id)');

        // --- site_review_events: review_id -> project_id, plus the sequence column --------
        $this->addSql('ALTER TABLE site_review_events ADD project_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE site_review_events e
            SET project_id = r.project_id
            FROM site_review_reviews r
            WHERE e.review_id = r.id
            SQL);
        $this->addSql('ALTER TABLE site_review_events ALTER COLUMN project_id SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_events DROP CONSTRAINT fk_8b5278213e2e969b');
        $this->addSql('DROP INDEX idx_8b5278213e2e969b');
        $this->addSql('ALTER TABLE site_review_events DROP COLUMN review_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE site_review_events
            ADD CONSTRAINT FK_8B527821166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE
            SQL);
        $this->addSql('CREATE INDEX IDX_8B527821166D1F9C ON site_review_events (project_id)');
        // Existing rows get sequential values assigned by Postgres in physical row
        // order, which for this append-only table matches creation order.
        $this->addSql('ALTER TABLE site_review_events ADD sequence BIGINT GENERATED ALWAYS AS IDENTITY');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8B5278215286D72B ON site_review_events (sequence)');

        // --- drop the SiteReview entity's own table last ----------------------------------
        $this->addSql('DROP TABLE site_review_reviews');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE site_review_reviews (
              id UUID NOT NULL,
              status VARCHAR(20) NOT NULL,
              submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
              created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
              project_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_b3c886f0166d1f9c ON site_review_reviews (project_id)');
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_site_review_in_progress ON site_review_reviews (project_id)
            WHERE ((status)::text = 'in-progress'::text)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE site_review_reviews
            ADD CONSTRAINT fk_b3c886f0166d1f9c FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE
            SQL);

        // Best effort only: the original review-per-batch grouping (which comments
        // and events belonged to the same submit) is gone from the data and cannot
        // be reconstructed. This synthesizes one Submitted review per project that
        // has any comments or events, so the restored schema is consistent and no
        // row is orphaned — it does not recover the original batch boundaries.
        $this->addSql(<<<'SQL'
            INSERT INTO site_review_reviews (id, status, submitted_at, created_at, project_id)
            SELECT gen_random_uuid(), 'submitted', now(), now(), project_id
            FROM (
                SELECT project_id FROM site_review_comments
                UNION
                SELECT project_id FROM site_review_events
            ) projects_with_data
            SQL);

        $this->addSql('ALTER TABLE site_review_comments ADD review_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET review_id = r.id
            FROM site_review_reviews r
            WHERE c.project_id = r.project_id
            SQL);
        $this->addSql('ALTER TABLE site_review_comments ALTER COLUMN review_id SET NOT NULL');
        // 'draft' does not exist in the restored enum, so any comment written
        // after the upgrade would fail to hydrate and break every page that
        // loads it. Pending is the inverse: in the old model a comment sitting
        // in an in-progress review was exactly that.
        $this->addSql("UPDATE site_review_comments SET status = 'pending' WHERE status = 'draft'");
        $this->addSql('ALTER TABLE site_review_comments DROP CONSTRAINT FK_7246C1CA166D1F9C');
        $this->addSql('DROP INDEX IDX_7246C1CA166D1F9C');
        $this->addSql('ALTER TABLE site_review_comments DROP COLUMN project_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE site_review_comments
            ADD CONSTRAINT fk_7246c1ca3e2e969b FOREIGN KEY (review_id) REFERENCES site_review_reviews (id) NOT DEFERRABLE
            SQL);
        $this->addSql('CREATE INDEX idx_7246c1ca3e2e969b ON site_review_comments (review_id)');

        $this->addSql('ALTER TABLE site_review_events ADD review_id UUID DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE site_review_events e
            SET review_id = r.id
            FROM site_review_reviews r
            WHERE e.project_id = r.project_id
            SQL);
        $this->addSql('ALTER TABLE site_review_events ALTER COLUMN review_id SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_events DROP CONSTRAINT FK_8B527821166D1F9C');
        $this->addSql('DROP INDEX IDX_8B527821166D1F9C');
        $this->addSql('DROP INDEX UNIQ_8B5278215286D72B');
        $this->addSql('ALTER TABLE site_review_events DROP COLUMN sequence');
        $this->addSql('ALTER TABLE site_review_events DROP COLUMN project_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE site_review_events
            ADD CONSTRAINT fk_8b5278213e2e969b FOREIGN KEY (review_id) REFERENCES site_review_reviews (id) NOT DEFERRABLE
            SQL);
        $this->addSql('CREATE INDEX idx_8b5278213e2e969b ON site_review_events (review_id)');
    }
}
