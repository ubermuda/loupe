<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009145806 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Copy the epic branch pattern of each project into the epicBranch value of its stored workflow copy';
    }

    /**
     * The engine reads the stored copy, so the pattern of the project moves in beside the rules. A blank pattern
     * means no epic branches, which is a copy with no value. A project with no settings row had the default pattern.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE workflow_bindings b
            SET definition = b.definition || jsonb_build_object('epicBranch', BTRIM(s.epic_branch_pattern))
            FROM board_automation_settings s
            WHERE s.project_id = b.project_id
                AND b.definition->'epicBranch' IS NULL
                AND BTRIM(COALESCE(s.epic_branch_pattern, '')) <> ''
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE workflow_bindings b
            SET definition = b.definition || jsonb_build_object('epicBranch', 'epic/{number}')
            WHERE b.definition->'epicBranch' IS NULL
                AND NOT EXISTS (SELECT 1 FROM board_automation_settings s WHERE s.project_id = b.project_id)
            SQL);
    }

    /** The old copies are not kept, so nothing restores them. */
    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
