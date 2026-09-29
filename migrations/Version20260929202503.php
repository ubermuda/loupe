<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929202503 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create bridge_commands and add the pause and capability columns to bridges';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_commands (id UUID NOT NULL, state VARCHAR(20) NOT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, bridge_id UUID NOT NULL, kind VARCHAR(20) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reason TEXT DEFAULT NULL, owner_id UUID NOT NULL, project_id UUID NOT NULL, worker_run_id UUID NOT NULL, requested_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_888E09D17E3C61F9 ON bridge_commands (owner_id)');
        $this->addSql('CREATE INDEX IDX_888E09D1166D1F9C ON bridge_commands (project_id)');
        $this->addSql('CREATE INDEX IDX_888E09D15414FAD ON bridge_commands (worker_run_id)');
        $this->addSql('CREATE INDEX IDX_888E09D14DA1E751 ON bridge_commands (requested_by_id)');
        $this->addSql('CREATE INDEX idx_bridge_commands_bridge_state ON bridge_commands (owner_id, bridge_id, state)');
        $this->addSql('CREATE INDEX idx_bridge_commands_state_expires ON bridge_commands (state, expires_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_command_pending_run ON bridge_commands (worker_run_id) WHERE ((state)::text = \'pending\'::text)');
        $this->addSql('ALTER TABLE bridge_commands ADD CONSTRAINT FK_888E09D17E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_commands ADD CONSTRAINT FK_888E09D1166D1F9C FOREIGN KEY (project_id) REFERENCES projects (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_commands ADD CONSTRAINT FK_888E09D15414FAD FOREIGN KEY (worker_run_id) REFERENCES bridge_worker_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridge_commands ADD CONSTRAINT FK_888E09D14DA1E751 FOREIGN KEY (requested_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE bridges ADD pause_requested BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE bridges ADD pause_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD paused_reported BOOLEAN DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD capabilities JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD pause_requested_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD CONSTRAINT FK_7BD739D11D8AE1D9 FOREIGN KEY (pause_requested_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7BD739D11D8AE1D9 ON bridges (pause_requested_by_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_commands');
        $this->addSql('ALTER TABLE bridges DROP CONSTRAINT FK_7BD739D11D8AE1D9');
        $this->addSql('DROP INDEX IDX_7BD739D11D8AE1D9');
        $this->addSql('ALTER TABLE bridges DROP pause_requested');
        $this->addSql('ALTER TABLE bridges DROP pause_requested_at');
        $this->addSql('ALTER TABLE bridges DROP paused_reported');
        $this->addSql('ALTER TABLE bridges DROP capabilities');
        $this->addSql('ALTER TABLE bridges DROP pause_requested_by_id');
    }
}
