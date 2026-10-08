<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008124047 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the harness and the account to the worker run fact rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD harness VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD account VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE bridge_worker_run_facts f SET harness = r.harness, account = r.account FROM bridge_worker_runs r WHERE r.id = f.run_id');
        // A fact outlives its run, and every agent run before the harness column ran Claude Code.
        // A fact whose run remains keeps the run's harness, as the fact writer copies it.
        $this->addSql(<<<'SQL'
            UPDATE bridge_worker_run_facts f SET harness = 'claude-code'
            WHERE f.harness IS NULL AND f.kind <> 'command'
                AND NOT EXISTS (SELECT 1 FROM bridge_worker_runs r WHERE r.id = f.run_id)
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP harness');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP account');
    }
}
