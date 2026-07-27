<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726225038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable revoked_at to api_tokens so revoking a token stops it authenticating without deleting the row';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens ADD revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP revoked_at');
    }
}
