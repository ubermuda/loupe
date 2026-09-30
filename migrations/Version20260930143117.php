<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930143117 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the open and merge times to forge pull requests';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE forge_pull_requests ADD merged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP opened_at');
        $this->addSql('ALTER TABLE forge_pull_requests DROP merged_at');
    }
}
