<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007172210 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the discovery proposals table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discovery_proposals (id UUID NOT NULL, created_card_id UUID DEFAULT NULL, position INT DEFAULT NULL, proposal_key VARCHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, body TEXT NOT NULL, open_card_number INT DEFAULT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_discovery_proposals_run ON discovery_proposals (run_id)');
        $this->addSql('ALTER TABLE discovery_proposals ADD CONSTRAINT FK_31F6B5B784E3FEC4 FOREIGN KEY (run_id) REFERENCES discovery_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discovery_proposals DROP CONSTRAINT FK_31F6B5B784E3FEC4');
        $this->addSql('DROP TABLE discovery_proposals');
    }
}
