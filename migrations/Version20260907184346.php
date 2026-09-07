<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907184346 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add board_card_documents, linking a card to the documents its work is written up in';
    }

    /**
     * Both foreign keys cascade, so neither module has to know the link exists
     * when it removes its own row. The pair is unique: linking the same
     * document twice says nothing the first link did not.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_documents (id UUID NOT NULL, card_id UUID NOT NULL, document_id UUID NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_4A7F3F5F4ACC9A20 ON board_card_documents (card_id)');
        $this->addSql('CREATE INDEX IDX_4A7F3F5FC33F7837 ON board_card_documents (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_document ON board_card_documents (card_id, document_id)');
        $this->addSql('ALTER TABLE board_card_documents ADD CONSTRAINT FK_board_card_documents_card FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board_card_documents ADD CONSTRAINT FK_board_card_documents_document FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_card_documents');
    }
}
