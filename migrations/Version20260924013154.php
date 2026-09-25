<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260924013154 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let a worker run be an interactive run that no bridge holds';
    }

    public function up(Schema $schema): void
    {
        // Every existing row, and every row the previous image writes, is a bridge worker.
        $this->addSql("ALTER TABLE bridge_worker_runs ADD kind VARCHAR(20) DEFAULT 'worker' NOT NULL");
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER bridge_id DROP NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX uniq_bridge_worker_run_interactive_open ON bridge_worker_runs (project_id, card_id, session_id) WHERE (kind = 'interactive' AND state = 'running')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The old column holds no run without a bridge. The state history cascades.
        $this->addSql('DELETE FROM bridge_worker_runs WHERE bridge_id IS NULL');
        $this->addSql('DROP INDEX uniq_bridge_worker_run_interactive_open');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP kind');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER bridge_id SET NOT NULL');
    }
}
