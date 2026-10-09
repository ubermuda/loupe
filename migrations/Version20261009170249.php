<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009170249 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the table that holds the agent reviews of pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE agent_reviews (id UUID NOT NULL, findings JSON NOT NULL, check_run_id BIGINT DEFAULT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, head_sha VARCHAR(64) NOT NULL, summary TEXT NOT NULL, conclusion VARCHAR(20) NOT NULL, worker_run_id UUID DEFAULT NULL, work_request_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, card_id UUID NOT NULL, pull_request_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_BB2389A5166D1F9C ON agent_reviews (project_id)');
        $this->addSql('CREATE INDEX IDX_BB2389A54ACC9A20 ON agent_reviews (card_id)');
        $this->addSql('CREATE INDEX IDX_BB2389A54CE0BF7E ON agent_reviews (pull_request_id)');
        $this->addSql('CREATE INDEX idx_agent_reviews_pull_request_head ON agent_reviews (pull_request_id, head_sha)');
        $this->addSql('ALTER TABLE agent_reviews ADD CONSTRAINT FK_BB2389A5166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_reviews ADD CONSTRAINT FK_BB2389A54ACC9A20 FOREIGN KEY (card_id) REFERENCES board_cards (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_reviews ADD CONSTRAINT FK_BB2389A54CE0BF7E FOREIGN KEY (pull_request_id) REFERENCES forge_pull_requests (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE agent_reviews');
    }
}
