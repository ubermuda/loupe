<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003051657 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Default fix_rounds of board_card_automations to 0, so an insert can leave it out';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_automations ALTER fix_rounds SET DEFAULT 0');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_card_automations ALTER fix_rounds DROP DEFAULT');
    }
}
