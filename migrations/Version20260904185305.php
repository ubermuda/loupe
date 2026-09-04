<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904185305 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Move a site-review comment anchor into its own table, and add the strokes column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_review_comment_anchors (id UUID NOT NULL, position INT NOT NULL, selector TEXT NOT NULL, text TEXT NOT NULL, quote TEXT DEFAULT NULL, quote_prefix TEXT DEFAULT NULL, quote_suffix TEXT DEFAULT NULL, comment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3F582CA8F8697D13 ON site_review_comment_anchors (comment_id)');
        $this->addSql('ALTER TABLE site_review_comment_anchors ADD CONSTRAINT FK_3F582CA8F8697D13 FOREIGN KEY (comment_id) REFERENCES site_review_comments (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_comments ADD strokes JSON DEFAULT NULL');

        // An empty selector was an unanchored page note, so it gets no anchor row.
        $this->addSql(<<<'SQL'
            INSERT INTO site_review_comment_anchors (id, comment_id, position, selector, text)
            SELECT gen_random_uuid(), id, 0, selector, text
            FROM site_review_comments
            WHERE selector <> ''
            SQL);

        // @contract-phase: the anchors table above now holds this data, and no code reads the column after this release. This takes the contract phase in the same release as the expansion, so a rollback to the previous image needs down() run by hand.
        $this->addSql('ALTER TABLE site_review_comments DROP selector');
        // @contract-phase: same statement as the selector drop above; the pair moved to site_review_comment_anchors together.
        $this->addSql('ALTER TABLE site_review_comments DROP text');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The columns come back with a default so an existing row satisfies NOT
        // NULL; the default then goes, because the entity never had one.
        $this->addSql("ALTER TABLE site_review_comments ADD selector TEXT NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE site_review_comments ADD text TEXT NOT NULL DEFAULT ''");

        // Only the first anchor fits back into the scalar columns. Any further
        // anchor of a comment is dropped.
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET selector = a.selector, text = a.text
            FROM site_review_comment_anchors a
            WHERE a.comment_id = c.id AND a.position = 0
            SQL);

        $this->addSql('ALTER TABLE site_review_comments ALTER selector DROP DEFAULT');
        $this->addSql('ALTER TABLE site_review_comments ALTER text DROP DEFAULT');
        $this->addSql('ALTER TABLE site_review_comment_anchors DROP CONSTRAINT FK_3F582CA8F8697D13');
        $this->addSql('DROP TABLE site_review_comment_anchors');
        $this->addSql('ALTER TABLE site_review_comments DROP strokes');
    }
}
