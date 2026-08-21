<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821015232 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add terms_accepted_at and terms_version to users, so acceptance of a given terms revision is recorded';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD terms_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD terms_version VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP terms_accepted_at');
        $this->addSql('ALTER TABLE users DROP terms_version');
    }
}
