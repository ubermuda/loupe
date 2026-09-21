<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260921020816 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Let the loupe-cli OAuth client request the mcp and projects scopes';
    }

    /**
     * League limits a client to its own scope list, so the CLI could ask for
     * `agent` alone and never reach the MCP endpoint. It needs `agent` for the
     * bridge endpoints, `mcp` for the MCP endpoint, and `projects` to cover
     * every project the person owns.
     *
     * Only the row this migration wrote is changed, so an operator who edited
     * the scopes keeps their own value.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE oauth2_client
            SET scopes = 'agent mcp projects'
            WHERE identifier = 'loupe-cli' AND scopes = 'agent'
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE oauth2_client
            SET scopes = 'agent'
            WHERE identifier = 'loupe-cli' AND scopes = 'agent mcp projects'
            SQL);
    }
}
