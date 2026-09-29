<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929202344 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the trigger column to bridge_worker_runs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs ADD trigger VARCHAR(20) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_worker_runs DROP trigger');
    }
}
