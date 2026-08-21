<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725204231 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add account deletion token to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD account_deletion_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD account_deletion_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP account_deletion_token_hash');
        $this->addSql('ALTER TABLE users DROP account_deletion_token_expires_at');
    }
}
