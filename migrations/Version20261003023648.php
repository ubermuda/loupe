<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003023648 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Record the work request of a worker run, and stop requiring its rule name';
    }

    /**
     * The rule, resume, column and trigger columns stay until a later release
     * drops them, because the previous image still reads them. The default
     * gives a row this image writes a rule name the previous image can read.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD work_kind VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD work_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD rule_id VARCHAR(100) DEFAULT NULL');
        // An interactive run keeps its name. A run of a work request, interactive too, carries its kind after the prefix.
        // No column links a run to its work request, so work_request_id and rule_id stay null.
        $this->addSql("UPDATE bridge_worker_runs SET work_kind = rule_name WHERE kind = 'interactive'");
        $this->addSql("UPDATE bridge_worker_runs SET work_kind = SUBSTRING(rule_name FROM 6) WHERE rule_name LIKE 'work:%' AND LENGTH(rule_name) > 5");
        $this->addSql("ALTER TABLE bridge_worker_runs ALTER rule_name SET DEFAULT ''");

        $this->addSql('ALTER TABLE bridge_worker_run_usage ADD work_kind VARCHAR(100) DEFAULT NULL');
        $this->addSql("UPDATE bridge_worker_run_usage u SET work_kind = u.rule_name FROM bridge_worker_runs r WHERE r.id = u.run_id AND r.kind = 'interactive'");
        $this->addSql("UPDATE bridge_worker_run_usage SET work_kind = SUBSTRING(rule_name FROM 6) WHERE rule_name LIKE 'work:%' AND LENGTH(rule_name) > 5");
        $this->addSql("ALTER TABLE bridge_worker_run_usage ALTER rule_name SET DEFAULT ''");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_run_usage ALTER rule_name DROP DEFAULT');
        $this->addSql('ALTER TABLE bridge_worker_run_usage DROP work_kind');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER rule_name DROP DEFAULT');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP work_kind');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP work_request_id');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP rule_id');
    }
}
