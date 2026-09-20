<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919183911 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Register the site-review widget as a public OAuth client';
    }

    /**
     * No redirect URI is stored, because it depends on the host that serves the
     * instance. WidgetClientRepository adds it at runtime. A row that already
     * holds this id stays as it is, so an operator who disabled it keeps it off.
     */
    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO oauth2_client (identifier, name, secret, redirect_uris, grants, scopes, active, allow_plain_text_pkce)
            VALUES ('loupe-site-review-widget', 'Loupe site-review widget', NULL, NULL, 'authorization_code refresh_token', 'site-review', true, false)
            ON CONFLICT (identifier) DO NOTHING
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM oauth2_client WHERE identifier = 'loupe-site-review-widget'");
    }
}
