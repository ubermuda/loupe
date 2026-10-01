<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001132926 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the head that the approval does not cover to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD uncovered_sha VARCHAR(64) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP uncovered_sha');
    }
}
