<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009004422 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the ready since time to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests ADD ready_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // A pull request that is ready already never sees the edge that stamps the time.
        $this->addSql("UPDATE forge_pull_requests SET ready_since = LOCALTIMESTAMP(0) WHERE ready_to_merge = true AND state = 'open'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP ready_since');
    }
}
