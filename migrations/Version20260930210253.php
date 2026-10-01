<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930210253 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add abandoned_move_token to board_card_automations';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_automations ADD abandoned_move_token UUID DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_automations DROP abandoned_move_token');
    }
}
