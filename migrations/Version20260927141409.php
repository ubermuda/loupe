<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927141409 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create forge pull requests, with a row for each GitHub pull request a card links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE forge_pull_requests (id UUID NOT NULL, state VARCHAR(20) NOT NULL, draft BOOLEAN NOT NULL, head_sha VARCHAR(64) DEFAULT NULL, base_branch VARCHAR(255) DEFAULT NULL, checks VARCHAR(20) NOT NULL, checks_sha VARCHAR(64) DEFAULT NULL, failed_checks JSON NOT NULL, mergeability VARCHAR(20) NOT NULL, review VARCHAR(20) NOT NULL, ready_to_merge BOOLEAN NOT NULL, refreshed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refresh_attempts INT NOT NULL, next_refresh_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, forge VARCHAR(50) NOT NULL, repository VARCHAR(255) NOT NULL, number INT NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6DBE76C3166D1F9C ON forge_pull_requests (project_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_forge_pull_requests_project_forge_repository_number ON forge_pull_requests (project_id, forge, repository, number)');
        $this->addSql('ALTER TABLE forge_pull_requests ADD CONSTRAINT FK_6DBE76C3166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        // The sweep reads a row with no refreshed_at first, so these get their state on its next tick.
        $this->addSql(<<<'SQL'
            INSERT INTO forge_pull_requests (id, project_id, forge, repository, number, state, draft, checks, failed_checks, mergeability, review, ready_to_merge, refresh_attempts)
            SELECT gen_random_uuid(), linked.project_id, 'github', linked.repository, linked.number, 'open', false, 'pending', '[]', 'unknown', 'none', false, 0
            FROM (
                SELECT DISTINCT card.project_id, LOWER(link.repository) AS repository, link.number
                FROM board_card_pull_requests link
                JOIN board_cards card ON card.id = link.card_id
                WHERE link.forge = 'github' AND link.repository IS NOT NULL AND link.number IS NOT NULL
            ) linked
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE forge_pull_requests');
    }
}
