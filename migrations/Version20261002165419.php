<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002165419 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create card_pauses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE card_pauses (id UUID NOT NULL, released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, release_reason VARCHAR(64) DEFAULT NULL, reason VARCHAR(64) NOT NULL, rule_id VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B75D98284ACC9A20 ON card_pauses (card_id)');
        $this->addSql('CREATE INDEX IDX_B75D9828166D1F9C ON card_pauses (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_card_pause_active_card ON card_pauses (card_id) WHERE (released_at IS NULL)');
        $this->addSql('ALTER TABLE card_pauses ADD CONSTRAINT FK_B75D98284ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE card_pauses ADD CONSTRAINT FK_B75D9828166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE card_pauses DROP CONSTRAINT FK_B75D98284ACC9A20');
        $this->addSql('ALTER TABLE card_pauses DROP CONSTRAINT FK_B75D9828166D1F9C');
        $this->addSql('DROP TABLE card_pauses');
    }
}
