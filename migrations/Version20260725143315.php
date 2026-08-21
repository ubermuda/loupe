<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725143315 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add wizard_completed_at to users, backfilled for existing accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD wizard_completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE users SET wizard_completed_at = created_at');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users DROP wizard_completed_at');
    }
}
