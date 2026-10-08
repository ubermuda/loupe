<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007190751 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add metrics to bridge_experiment_definitions';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions ADD metrics JSON DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_experiment_definitions DROP metrics');
    }
}
