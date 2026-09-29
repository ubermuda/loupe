<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928175142 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add card watches and waits for wait items, and let a Loupe ask hold no session';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_card_waits (id UUID NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_reason VARCHAR(30) DEFAULT NULL, trigger VARCHAR(30) NOT NULL, reason VARCHAR(200) NOT NULL, document_id UUID DEFAULT NULL, version_number INT DEFAULT NULL, run_id UUID DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, watch_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3A51A152C7C58135 ON inbox_card_waits (watch_id)');
        $this->addSql('CREATE TABLE inbox_card_watches (id UUID NOT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, dismissed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, card_id UUID NOT NULL, card_number INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, item_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FCCE4BE6166D1F9C ON inbox_card_watches (project_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FCCE4BE6126F525E ON inbox_card_watches (item_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_inbox_card_watches_open_card ON inbox_card_watches (card_id) WHERE (closed_at IS NULL)');
        $this->addSql('ALTER TABLE inbox_card_waits ADD CONSTRAINT FK_3A51A152C7C58135 FOREIGN KEY (watch_id) REFERENCES inbox_card_watches (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_card_watches ADD CONSTRAINT FK_FCCE4BE6166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_card_watches ADD CONSTRAINT FK_FCCE4BE6126F525E FOREIGN KEY (item_id) REFERENCES inbox_items (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_asks ADD origin VARCHAR(20) DEFAULT \'agent\' NOT NULL');
        $this->addSql('ALTER TABLE inbox_asks ALTER session_id DROP NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The older schema has no wait kind and no ask without a session.
        $this->addSql("DELETE FROM inbox_items WHERE kind = 'wait'");
        $this->addSql("DELETE FROM inbox_asks WHERE origin = 'loupe' OR session_id IS NULL");
        $this->addSql('ALTER TABLE inbox_card_waits DROP CONSTRAINT FK_3A51A152C7C58135');
        $this->addSql('ALTER TABLE inbox_card_watches DROP CONSTRAINT FK_FCCE4BE6166D1F9C');
        $this->addSql('ALTER TABLE inbox_card_watches DROP CONSTRAINT FK_FCCE4BE6126F525E');
        $this->addSql('DROP TABLE inbox_card_waits');
        $this->addSql('DROP TABLE inbox_card_watches');
        $this->addSql('ALTER TABLE inbox_asks DROP origin');
        $this->addSql('ALTER TABLE inbox_asks ALTER session_id SET NOT NULL');
    }
}
