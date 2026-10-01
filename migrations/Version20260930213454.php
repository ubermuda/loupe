<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930213454 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add run_id to board_card_events, unique per card';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_events ADD run_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_events_card_run ON board_card_events (card_id, run_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_board_card_events_card_run');
        $this->addSql('ALTER TABLE board_card_events DROP run_id');
    }
}
