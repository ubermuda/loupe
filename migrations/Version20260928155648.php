<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260928155648 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add the ids of the announced reviews to forge pull requests';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE forge_pull_requests ADD announced_review_ids JSON DEFAULT '[]' NOT NULL");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE forge_pull_requests DROP announced_review_ids');
    }
}
