<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260828142036 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Add token_tail to api_tokens so a token can be shown masked';
    }

    public function up(Schema $schema): void
    {
        // Nullable and not backfilled: only the request that issued a token ever
        // held its raw value, so existing rows have no recoverable tail.
        $this->addSql('ALTER TABLE api_tokens ADD token_tail VARCHAR(4) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_tokens DROP token_tail');
    }
}
