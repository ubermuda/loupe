<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006194956 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the readiness guide and agent first-seen stamps to projects, and hide the guide on boards already in use';
    }

    /** A project with a card outside the Backlog already uses its board, so it never sees the guide. */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects ADD readiness_guide_hidden_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE projects ADD agent_first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE projects SET readiness_guide_hidden_at = NOW()
            WHERE EXISTS (
                SELECT 1 FROM board_cards c
                JOIN board_columns col ON col.id = c.column_id
                WHERE c.project_id = projects.id AND NOT col.is_default
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE projects DROP readiness_guide_hidden_at');
        $this->addSql('ALTER TABLE projects DROP agent_first_seen_at');
    }
}
