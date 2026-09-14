<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914151446 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the inbox item, ask and link tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_ask_items (id UUID NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ask_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A6DCC624B93F8B63 ON inbox_ask_items (ask_id)');
        $this->addSql('CREATE INDEX IDX_A6DCC624126F525E ON inbox_ask_items (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_ask_item ON inbox_ask_items (ask_id, item_id)');
        $this->addSql('CREATE TABLE inbox_asks (id UUID NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, session_id UUID NOT NULL, bridge_id UUID DEFAULT NULL, context TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID DEFAULT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_72F385034ACC9A20 ON inbox_asks (card_id)');
        $this->addSql('CREATE INDEX IDX_72F38503166D1F9C ON inbox_asks (project_id)');
        $this->addSql('CREATE INDEX idx_inbox_asks_session ON inbox_asks (session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_asks_open_session ON inbox_asks (session_id) WHERE (closed_at IS NULL)');
        $this->addSql('CREATE TABLE inbox_item_cards (id UUID NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E71324C6126F525E ON inbox_item_cards (item_id)');
        $this->addSql('CREATE INDEX IDX_E71324C64ACC9A20 ON inbox_item_cards (card_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_item_card ON inbox_item_cards (item_id, card_id)');
        $this->addSql('CREATE TABLE inbox_item_documents (id UUID NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, item_id UUID NOT NULL, document_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7951DB80126F525E ON inbox_item_documents (item_id)');
        $this->addSql('CREATE INDEX IDX_7951DB80C33F7837 ON inbox_item_documents (document_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_item_document ON inbox_item_documents (item_id, document_id)');
        $this->addSql('CREATE TABLE inbox_items (id UUID NOT NULL, state VARCHAR(20) NOT NULL, selected_options JSON NOT NULL, answer_text TEXT DEFAULT NULL, close_note TEXT DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, search_vector TSVECTOR DEFAULT NULL, number INT NOT NULL, kind VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, blocking BOOLEAN NOT NULL, body TEXT DEFAULT NULL, options JSON NOT NULL, multiple BOOLEAN NOT NULL, free_text BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, search_language VARCHAR(20) DEFAULT \'english\' NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F10D0C99166D1F9C ON inbox_items (project_id)');
        // Written by hand because DBAL's Postgres platform emits no USING clause:
        // a B-tree index on a tsvector is built and never used by @@.
        $this->addSql('CREATE INDEX idx_inbox_items_search_vector ON inbox_items USING gin (search_vector)');
        $this->addSql('CREATE INDEX idx_inbox_items_project_search_language ON inbox_items (project_id, search_language)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_item_project_number ON inbox_items (project_id, number)');
        $this->addSql('ALTER TABLE inbox_ask_items ADD CONSTRAINT FK_A6DCC624B93F8B63 FOREIGN KEY (ask_id) REFERENCES inbox_asks (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_ask_items ADD CONSTRAINT FK_A6DCC624126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_asks ADD CONSTRAINT FK_72F385034ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_asks ADD CONSTRAINT FK_72F38503166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_item_cards ADD CONSTRAINT FK_E71324C6126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_item_cards ADD CONSTRAINT FK_E71324C64ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_item_documents ADD CONSTRAINT FK_7951DB80126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_item_documents ADD CONSTRAINT FK_7951DB80C33F7837 FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_items ADD CONSTRAINT FK_F10D0C99166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_ask_items DROP CONSTRAINT FK_A6DCC624B93F8B63');
        $this->addSql('ALTER TABLE inbox_ask_items DROP CONSTRAINT FK_A6DCC624126F525E');
        $this->addSql('ALTER TABLE inbox_asks DROP CONSTRAINT FK_72F385034ACC9A20');
        $this->addSql('ALTER TABLE inbox_asks DROP CONSTRAINT FK_72F38503166D1F9C');
        $this->addSql('ALTER TABLE inbox_item_cards DROP CONSTRAINT FK_E71324C6126F525E');
        $this->addSql('ALTER TABLE inbox_item_cards DROP CONSTRAINT FK_E71324C64ACC9A20');
        $this->addSql('ALTER TABLE inbox_item_documents DROP CONSTRAINT FK_7951DB80126F525E');
        $this->addSql('ALTER TABLE inbox_item_documents DROP CONSTRAINT FK_7951DB80C33F7837');
        $this->addSql('ALTER TABLE inbox_items DROP CONSTRAINT FK_F10D0C99166D1F9C');
        $this->addSql('DROP TABLE inbox_ask_items');
        $this->addSql('DROP TABLE inbox_asks');
        $this->addSql('DROP TABLE inbox_item_cards');
        $this->addSql('DROP TABLE inbox_item_documents');
        $this->addSql('DROP TABLE inbox_items');
    }
}
