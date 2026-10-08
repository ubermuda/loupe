<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007141449 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the peak context tokens to the worker runs and the fact rows';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD peak_context_tokens BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_worker_run_facts ADD peak_context_tokens BIGINT DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP peak_context_tokens');
        $this->addSql('ALTER TABLE bridge_worker_run_facts DROP peak_context_tokens');
    }
}
