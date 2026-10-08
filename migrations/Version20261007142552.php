<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007142552 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the model and the effort a work request asks for';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests ADD model VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE work_requests ADD effort VARCHAR(16) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE work_requests DROP model');
        $this->addSql('ALTER TABLE work_requests DROP effort');
    }
}
