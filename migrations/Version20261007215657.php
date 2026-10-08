<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007215657 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Keep the microseconds of the start and the end of a worker run';
    }

    public function up(Schema $schema): void
    {
        // @contract-phase: the column only gains a fraction, and the previous image reads a time with a fraction as before.
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER started_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
        // @contract-phase: same as above, for the end of the run.
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER ended_at TYPE TIMESTAMP(6) WITHOUT TIME ZONE');
    }

    /** Narrowing drops the fractions written since the deploy. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER started_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE bridge_worker_runs ALTER ended_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
    }
}
