<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261002170008 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Index work_requests by card';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_work_requests_card ON work_requests (card_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_work_requests_card');
    }
}
