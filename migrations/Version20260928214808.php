<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928214808 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Clear the read time of forge pull requests that no read ever succeeded on';
    }

    public function up(Schema $schema): void
    {
        // A successful read always stores the head commit, so a row without one was never read.
        $this->addSql('UPDATE forge_pull_requests SET refreshed_at = NULL WHERE head_sha IS NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
    }
}
