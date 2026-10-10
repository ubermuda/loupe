<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009203200 extends AbstractMigration
{
    private const array BOOLEANS = [
        'comment_on_fix_queued',
        'comment_on_stale_approval',
        'sync_behind',
        'merge_pull_requests',
        'change_base',
        'epic_draft_switch',
        'close_epic_pull_requests',
        'open_epic_pull_requests',
        'post_widget_reviews',
        'site_review_check',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Drop the board automation switches, the strategies, the loop limit and the epic branch pattern, which the workflow now holds';
    }

    public function up(Schema $schema): void
    {
        foreach (self::BOOLEANS as $column) {
            // @contract-phase: this release removes every reader of the column, so no code reads or writes it.
            $this->addSql(\sprintf('ALTER TABLE board_automation_settings DROP %s', $column));
        }
        foreach (['merge_strategy', 'fix_strategy', 'loop_limit', 'epic_branch_pattern'] as $column) {
            // @contract-phase: this release removes every reader of the column, so no code reads or writes it.
            $this->addSql(\sprintf('ALTER TABLE board_automation_settings DROP %s', $column));
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_automation_settings ADD merge_strategy VARCHAR(20) DEFAULT \'worker\' NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD fix_strategy VARCHAR(20) DEFAULT \'fresh\' NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD loop_limit INT DEFAULT 3 NOT NULL');
        $this->addSql('ALTER TABLE board_automation_settings ADD epic_branch_pattern VARCHAR(255) DEFAULT \'epic/{number}\'');
        foreach (self::BOOLEANS as $column) {
            $this->addSql(\sprintf('ALTER TABLE board_automation_settings ADD %s BOOLEAN DEFAULT false NOT NULL', $column));
        }
    }
}
