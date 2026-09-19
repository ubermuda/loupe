<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919185137 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the OAuth client metadata document table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE oauth_client_metadata_document (client_identifier VARCHAR(32) NOT NULL, url VARCHAR(255) NOT NULL, client_name VARCHAR(128) NOT NULL, fetched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (client_identifier))');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_client_metadata_document');
    }
}
