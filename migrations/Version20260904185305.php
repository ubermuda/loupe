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
        return 'Add site-review comment anchors and the strokes column, and make the scalar anchor columns nullable';
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

        // The entity stops mapping these two, so an insert no longer supplies
        // them. They stay in the table, and a later release drops them.
        $this->addSql('ALTER TABLE site_review_comments ALTER selector DROP NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments ALTER text DROP NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // A row written after up() has null scalars, so restore them from the
        // first anchor before NOT NULL comes back. A comment with no anchor is
        // an unanchored page note, which the scalars spell as an empty string.
        $this->addSql(<<<'SQL'
            UPDATE site_review_comments c
            SET selector = a.selector, text = a.text
            FROM site_review_comment_anchors a
            WHERE a.comment_id = c.id AND a.position = 0
              AND (c.selector IS NULL OR c.text IS NULL)
            SQL);
        $this->addSql("UPDATE site_review_comments SET selector = COALESCE(selector, ''), text = COALESCE(text, '')");

        $this->addSql('ALTER TABLE site_review_comments ALTER selector SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_comments ALTER text SET NOT NULL');
        $this->addSql('ALTER TABLE site_review_comment_anchors DROP CONSTRAINT FK_3F582CA8F8697D13');
        $this->addSql('DROP TABLE site_review_comment_anchors');
        $this->addSql('ALTER TABLE site_review_comments DROP strokes');
    }
}
