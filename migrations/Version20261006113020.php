<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006113020 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Widen bridge_commands.kind and allow one pending command per run and bridge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bridge_command_pending_run');
        $this->addSql('ALTER TABLE bridge_commands ALTER kind TYPE VARCHAR(32)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_command_pending_run ON bridge_commands (worker_run_id, bridge_id) WHERE ((state)::text = \'pending\'::text)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM bridge_commands WHERE kind = \'collect-session-usage\'');
        $this->addSql('DROP INDEX uniq_bridge_command_pending_run');
        $this->addSql('ALTER TABLE bridge_commands ALTER kind TYPE VARCHAR(20)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_command_pending_run ON bridge_commands (worker_run_id) WHERE ((state)::text = \'pending\'::text)');
    }
}
