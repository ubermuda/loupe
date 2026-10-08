<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007191133 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the worker run bucket time table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridge_worker_run_bucket_times (id UUID NOT NULL, bucket VARCHAR(64) NOT NULL, ms BIGINT NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_12652BF984E3FEC4 ON bridge_worker_run_bucket_times (run_id)');
        $this->addSql('CREATE INDEX idx_bridge_worker_run_bucket_time_bucket ON bridge_worker_run_bucket_times (bucket)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bridge_worker_run_bucket_time ON bridge_worker_run_bucket_times (run_id, bucket)');
        $this->addSql('ALTER TABLE bridge_worker_run_bucket_times ADD CONSTRAINT FK_12652BF984E3FEC4 FOREIGN KEY (run_id) REFERENCES bridge_worker_runs (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bridge_worker_run_bucket_times');
    }
}
