<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002202631 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the terminal column window to the board settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD terminal_window_days INT DEFAULT 3 NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP terminal_window_days');
    }
}
