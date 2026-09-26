<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260925235235 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Keep interactive runs out of the unique index of worker run reports';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at) WHERE (run_key IS NULL AND kind = 'worker')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The old index assumed that no interactive run names a bridge.
        $this->addSql("UPDATE bridge_worker_runs SET bridge_id = NULL WHERE kind = 'interactive'");
        $this->addSql('DROP INDEX uniq_bridge_worker_run_report');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_report ON bridge_worker_runs (project_id, bridge_id, card_id, started_at) WHERE (run_key IS NULL)');
    }
}
