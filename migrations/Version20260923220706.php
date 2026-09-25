<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923220706 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Give worker runs a structured result and a link to the run they resume';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD result_status VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD result_fields JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD resume_skipped VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD resume_index SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD resume_cap SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD card_column TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD continues_run_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_runs ADD CONSTRAINT FK_8DAF6488A421093F FOREIGN KEY (continues_run_id) REFERENCES bridge_worker_runs (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_8DAF6488A421093F ON bridge_worker_runs (continues_run_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP CONSTRAINT FK_8DAF6488A421093F');
        $this->addSql('DROP INDEX IDX_8DAF6488A421093F');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP result_status');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP result_fields');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP resume_skipped');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP resume_index');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP resume_cap');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP card_column');
        $this->addSql('ALTER TABLE bridge_worker_runs DROP continues_run_id');
    }
}
