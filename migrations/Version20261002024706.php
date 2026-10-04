<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002024706 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create work_requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE work_requests (id UUID NOT NULL, state VARCHAR(20) NOT NULL, bridge_id UUID DEFAULT NULL, claim_token UUID DEFAULT NULL, lease_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claims INT DEFAULT 0 NOT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reason VARCHAR(64) DEFAULT NULL, card_id UUID NOT NULL, card_number INT NOT NULL, kind VARCHAR(40) NOT NULL, capability VARCHAR(40) DEFAULT NULL, rule_id VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AE305C0D166D1F9C ON work_requests (project_id)');
        $this->addSql('CREATE INDEX idx_work_requests_project_state ON work_requests (project_id, state)');
        $this->addSql('CREATE INDEX idx_work_requests_state_lease ON work_requests (state, lease_until)');
        $this->addSql('CREATE UNIQUE INDEX uniq_work_request_live_card_kind ON work_requests (card_id, kind) WHERE ((state)::text = ANY (ARRAY[(\'open\'::character varying)::text, (\'claimed\'::character varying)::text]))');
        $this->addSql('ALTER TABLE work_requests ADD CONSTRAINT FK_AE305C0D166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP CONSTRAINT FK_AE305C0D166D1F9C');
        $this->addSql('DROP TABLE work_requests');
    }
}
