<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911192709 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rename board_cards.origin to reporter';
    }

    // A rename rather than a drop and an add: the table is deployed and every
    // row carries a value. No index or constraint names the column.
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards RENAME COLUMN origin TO reporter');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards RENAME COLUMN reporter TO origin');
    }
}
