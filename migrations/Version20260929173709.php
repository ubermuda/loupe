<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929173709 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the trigger of a worker run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_event_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_forge VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_repository VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_pull_request_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_head_sha VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger_reason VARCHAR(100) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_event_type');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_forge');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_repository');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_pull_request_number');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_head_sha');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger_reason');
    }
}
