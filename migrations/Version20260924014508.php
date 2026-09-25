<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924014508 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the parent epic and the lane setting to board cards';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD lane_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE board_cards ADD parent_card_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board_cards ADD CONSTRAINT FK_A67FBFD63884EE66 FOREIGN KEY (parent_card_id) REFERENCES board_cards (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_A67FBFD63884EE66 ON board_cards (parent_card_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP CONSTRAINT FK_A67FBFD63884EE66');
        $this->addSql('DROP INDEX IDX_A67FBFD63884EE66');
        $this->addSql('ALTER TABLE board_cards DROP lane_enabled');
        $this->addSql('ALTER TABLE board_cards DROP parent_card_id');
    }
}
