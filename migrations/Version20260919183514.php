<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919183514 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Register the loupe-cli OAuth client for the device flow';
    }

    /**
     * A public client: no secret and no redirect URI. An existing row with this
     * id is left as it is, so an operator's own change to it survives.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO oauth2_client (identifier, name, secret, redirect_uris, grants, scopes, active, allow_plain_text_pkce)
            VALUES ('loupe-cli', 'Loupe CLI', NULL, NULL, 'urn:ietf:params:oauth:grant-type:device_code refresh_token', 'agent', true, false)
            ON CONFLICT (identifier) DO NOTHING
            SQL);
    }

    /** The foreign keys cascade, so this also deletes every token the client holds. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM oauth2_client WHERE identifier = 'loupe-cli'");
    }
}
