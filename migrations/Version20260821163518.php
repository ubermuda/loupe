<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821163518 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add suspended_at, suspended_reason and suspended_by_id to users, for admin-applied account suspension';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD suspended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspended_reason VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD suspended_by_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E914878AA7 FOREIGN KEY (suspended_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_1483A5E914878AA7 ON users (suspended_by_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E914878AA7');
        $this->addSql('DROP INDEX IDX_1483A5E914878AA7');
        $this->addSql('ALTER TABLE users DROP suspended_at');
        $this->addSql('ALTER TABLE users DROP suspended_reason');
        $this->addSql('ALTER TABLE users DROP suspended_by_id');
    }
}
