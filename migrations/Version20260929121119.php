<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929121119 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the worker pool of each run and the pool use of each bridge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD worker_pool VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridges ADD worker_pools JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP worker_pool');
        $this->addSql('ALTER TABLE bridges DROP worker_pools');
    }
}
