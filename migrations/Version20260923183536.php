<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923183536 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create board_card_links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE board_card_links (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source_card_id UUID NOT NULL, target_card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_45B884623132248 ON board_card_links (source_card_id)');
        $this->addSql('CREATE INDEX IDX_45B8846281A4234B ON board_card_links (target_card_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_card_link ON board_card_links (source_card_id, target_card_id)');
        $this->addSql('ALTER TABLE board_card_links ADD CONSTRAINT FK_45B884623132248 FOREIGN KEY (source_card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board_card_links ADD CONSTRAINT FK_45B8846281A4234B FOREIGN KEY (target_card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE board_card_links');
    }
}
