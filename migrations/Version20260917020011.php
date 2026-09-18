<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260917020011 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store shared site-feedback replies';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_review_replies (id UUID NOT NULL, body TEXT NOT NULL, submission_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, comment_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7AB80AD5F8697D13 ON site_review_replies (comment_id)');
        $this->addSql('CREATE INDEX IDX_7AB80AD5F675F31B ON site_review_replies (author_id)');
        $this->addSql('CREATE INDEX IDX_7AB80AD5F8697D138B8E8428BF396750 ON site_review_replies (comment_id, created_at, id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7AB80AD5F8697D13E1FD4933 ON site_review_replies (comment_id, submission_id)');
        $this->addSql('ALTER TABLE site_review_replies ADD CONSTRAINT FK_7AB80AD5F8697D13 FOREIGN KEY (comment_id) REFERENCES site_review_comments (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE site_review_replies ADD CONSTRAINT FK_7AB80AD5F675F31B FOREIGN KEY (author_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_review_replies DROP CONSTRAINT FK_7AB80AD5F8697D13');
        $this->addSql('ALTER TABLE site_review_replies DROP CONSTRAINT FK_7AB80AD5F675F31B');
        $this->addSql('DROP TABLE site_review_replies');
    }
}
