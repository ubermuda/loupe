<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006121137 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the board card automations table with the automation record of each card';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the card automation record is removed in this release, so no code reads or writes board_card_automations.
        $this->addSql('DROP TABLE IF EXISTS board_card_automations');
    }

    /** The table comes back empty, because the rows it held are gone. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_automations (id UUID NOT NULL, fix_rounds INT DEFAULT 0 NOT NULL, blocked_reason VARCHAR(50) DEFAULT NULL, last_action VARCHAR(20) DEFAULT NULL, last_action_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, card_id UUID NOT NULL, abandoned_move_token UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9A61F5D24ACC9A20 ON board_card_automations (card_id)');
        $this->addSql('ALTER TABLE board_card_automations ADD CONSTRAINT FK_9A61F5D24ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
    }
}
