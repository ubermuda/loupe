<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907122803 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add board_card_site_review_comments, linking a card to the comments made against it';
    }

    /**
     * Both foreign keys cascade. The comment side lets DeleteCommentHandler
     * remove a comment without SiteReview knowing a link existed, and the card
     * side means no ordering rule governs the two ProjectDeleting listeners.
     *
     * comment_id is unique: a comment names one card, and a replayed event must
     * not produce a second row.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_site_review_comments (id UUID NOT NULL, card_id UUID NOT NULL, comment_id UUID NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_82D42AF4ACC9A20 ON board_card_site_review_comments (card_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_82D42AFF8697D13 ON board_card_site_review_comments (comment_id)');
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD CONSTRAINT FK_board_card_srn_card FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board_card_site_review_comments ADD CONSTRAINT FK_board_card_srn_comment FOREIGN KEY (comment_id) REFERENCES site_review_comments (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_card_site_review_comments');
    }
}
