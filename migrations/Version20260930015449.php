<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930015449 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_card_events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_events (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, actor_kind VARCHAR(20) NOT NULL, detail JSONB DEFAULT \'[]\' NOT NULL, occurred_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, project_id UUID NOT NULL, actor_user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E3C3F54D4ACC9A20 ON board_card_events (card_id)');
        $this->addSql('CREATE INDEX IDX_E3C3F54D166D1F9C ON board_card_events (project_id)');
        $this->addSql('CREATE INDEX IDX_E3C3F54D859B83FF ON board_card_events (actor_user_id)');
        $this->addSql('CREATE INDEX idx_board_card_events_card_occurred ON board_card_events (card_id, occurred_at, id)');
        $this->addSql('ALTER TABLE board_card_events ADD CONSTRAINT FK_E3C3F54D4ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_events ADD CONSTRAINT FK_E3C3F54D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_events ADD CONSTRAINT FK_E3C3F54D859B83FF FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_events DROP CONSTRAINT FK_E3C3F54D4ACC9A20');
        $this->addSql('ALTER TABLE board_card_events DROP CONSTRAINT FK_E3C3F54D166D1F9C');
        $this->addSql('ALTER TABLE board_card_events DROP CONSTRAINT FK_E3C3F54D859B83FF');
        $this->addSql('DROP TABLE board_card_events');
    }
}
