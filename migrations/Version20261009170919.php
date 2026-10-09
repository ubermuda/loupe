<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009170919 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the agent review switch and its failing severities to the board automation settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD agent_review BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD agent_review_failing_severities JSON DEFAULT \'["important"]\' NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings DROP agent_review');
        $this->addSql('ALTER TABLE board_automation_settings DROP agent_review_failing_severities');
    }
}
