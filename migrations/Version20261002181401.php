<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002181401 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the card paused wait: its pause on inbox_card_waits and its switch on inbox_project_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_card_waits ADD pause_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE inbox_project_settings ADD card_paused BOOLEAN DEFAULT true NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_card_waits DROP pause_id');
        $this->addSql('ALTER TABLE inbox_project_settings DROP card_paused');
    }
}
