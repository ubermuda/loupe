<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804225237 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make users.full_name NOT NULL: every account now has a display name.';
    }

    public function up(Schema $schema): void
    {
        // Backfill first, or the constraint below fails on any account created
        // while the column was nullable. initcap() capitalizes after every
        // non-alphanumeric, so hyphenated names come out the same way the
        // application derives them; left() keeps the result inside the column.
        $this->addSql(<<<'SQL'
            UPDATE users
            SET full_name = left(initcap(replace(replace(split_part(split_part(email, '@', 1), '+', 1), '.', ' '), '_', ' ')), 150)
            WHERE full_name IS NULL
            SQL);

        $this->addSql('ALTER TABLE users ALTER full_name SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // The backfilled names stay: nothing records which rows had none, and a
        // name is not harmful to keep.
        $this->addSql('ALTER TABLE users ALTER full_name DROP NOT NULL');
    }
}
