<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260822124940 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add last_signed_in_at to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD last_signed_in_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP last_signed_in_at');
    }
}
