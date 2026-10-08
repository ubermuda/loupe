<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007170915 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the harness, the account, the model and the harness session id of each worker run';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD harness VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD account VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD model VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD harness_session_id VARCHAR(100) DEFAULT NULL');
        // Every bridge before this release ran Claude Code. A command run runs no harness.
        $this->addSql("UPDATE bridge_worker_runs SET harness = 'claude-code' WHERE kind IN ('worker', 'interactive')");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP harness');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP account');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP model');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP harness_session_id');
    }
}
