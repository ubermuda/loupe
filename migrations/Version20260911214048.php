<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911214048 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add board_cards.reporter beside origin and backfill it';
    }

    /**
     * Release 1 of the origin-to-reporter rename, and it only expands.
     *
     * The column stays nullable with no default. An image that predates it
     * writes a row without it, and a default would stamp a value on that row
     * that nobody chose. `origin` keeps its NOT NULL, so the older image reads
     * and writes exactly what it did before.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards ADD reporter VARCHAR(20) DEFAULT NULL');
        $this->addSql('UPDATE board_cards SET reporter = origin');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_cards DROP reporter');
    }
}
