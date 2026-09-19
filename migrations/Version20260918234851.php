<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260918234851 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Store a label colour on each board column, backfilled with the colour it showed before';
    }

    /**
     * Until now a column's colour came from its role and position: terminal
     * green, default neutral, the others lime, purple and amber in turn. The
     * backfill writes that colour, so no board changes colour on upgrade.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_columns ADD tone VARCHAR(20) DEFAULT \'neutral\' NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE board_columns SET tone = CASE
                WHEN terminal THEN 'green'
                WHEN is_default THEN 'neutral'
                ELSE (ARRAY['lime', 'purple', 'amber'])[(((position - 1) % 3) + 3) % 3 + 1]
            END
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_columns DROP tone');
    }
}
