<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007162854 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the model and the effort of the work request of the run to a bridge command';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands ADD model VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bridge_commands ADD effort VARCHAR(16) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridge_commands DROP model');
        $this->addSql('ALTER TABLE bridge_commands DROP effort');
    }
}
