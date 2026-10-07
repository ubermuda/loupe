<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007193929 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the host metrics to the worker run fact rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD mean_cpu_pct DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD peak_mem_bytes BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD peak_swap_bytes BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD concurrent_runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD on_battery BOOLEAN DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP mean_cpu_pct');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP peak_mem_bytes');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP peak_swap_bytes');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP concurrent_runs');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP on_battery');
    }
}
