<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914160424 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Create the bridges table that bridge heartbeats write';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bridges (id UUID NOT NULL, projects JSON NOT NULL, cli_version VARCHAR(100) NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7BD739D17E3C61F9 ON bridges (owner_id)');
        $this->addSql('ALTER TABLE bridges ADD CONSTRAINT FK_7BD739D17E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bridges DROP CONSTRAINT FK_7BD739D17E3C61F9');
        $this->addSql('DROP TABLE bridges');
    }
}
