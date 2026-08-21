<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725152603 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Make users.password nullable for OAuth-only accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ALTER password DROP NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // Social login creates accounts with a null password, so restoring the
        // NOT NULL constraint would fail (or require destroying those accounts).
        $this->throwIrreversibleMigrationException('OAuth-only users have null passwords; NOT NULL cannot be restored.');
    }
}
