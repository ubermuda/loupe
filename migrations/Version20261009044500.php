<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009044500 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the stuck delay to the board settings and the stuck announcement to pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD stuck_delay_minutes INT DEFAULT 15 NOT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD stuck_announced_for TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP stuck_delay_minutes');
        $this->addSql('ALTER TABLE forge_pull_requests DROP stuck_announced_for');
    }
}
