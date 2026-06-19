<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260619155932 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scope column to api_tokens, backfilling existing rows to mcp.';
    }

    public function up(Schema $schema): void
    {
        // Add with a temporary default so existing rows are backfilled to 'mcp',
        // then drop the default so the application always supplies the scope.
        $this->addSql("ALTER TABLE api_tokens ADD scope VARCHAR(255) DEFAULT 'mcp' NOT NULL");
        $this->addSql('ALTER TABLE api_tokens ALTER scope DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_tokens DROP scope');
    }
}
