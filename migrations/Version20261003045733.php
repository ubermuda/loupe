<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003045733 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Turn on the epic draft and close writes for each project whose board automation is on';
    }

    /**
     * A project with no settings row has its automation on by default, so it gets
     * a row with the defaults and the two writes on.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE board_automation_settings SET epic_draft_switch = true, close_epic_pull_requests = true WHERE enabled = true');
        $this->addSql(<<<'SQL'
            INSERT INTO board_automation_settings (id, project_id, enabled, merge_strategy, fix_strategy, loop_limit, epic_draft_switch, close_epic_pull_requests)
            SELECT gen_random_uuid(), p.id, true, 'worker', 'fresh', 3, true, true
            FROM projects p
            WHERE NOT EXISTS (SELECT 1 FROM board_automation_settings s WHERE s.project_id = p.id)
            SQL);
    }

    /** The rows this inserts hold the defaults, so they stay. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE board_automation_settings SET epic_draft_switch = false, close_epic_pull_requests = false');
    }
}
