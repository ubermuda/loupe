<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919182035 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the inbox replies table with the inbox conversation feature';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the inbox conversation is removed in this release, so no code reads or writes inbox_replies.
        $this->addSql('DROP TABLE IF EXISTS inbox_replies');
    }

    /** The table comes back empty, because the replies it held are gone. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_replies (id UUID NOT NULL, body TEXT NOT NULL, submission_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_953461D5126F525E ON inbox_replies (item_id)');
        $this->addSql('CREATE INDEX IDX_953461D5F675F31B ON inbox_replies (author_id)');
        $this->addSql('CREATE INDEX IDX_953461D5126F525E8B8E8428BF396750 ON inbox_replies (item_id, created_at, id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_953461D5126F525EE1FD4933 ON inbox_replies (item_id, submission_id)');
        $this->addSql('ALTER TABLE inbox_replies ADD CONSTRAINT FK_953461D5126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_replies ADD CONSTRAINT FK_953461D5F675F31B FOREIGN KEY (author_id) REFERENCES users (id) NOT DEFERRABLE');
    }
}
