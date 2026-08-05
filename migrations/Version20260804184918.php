<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804184918 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop users.username and make users.full_name nullable: the email is the identity, and a display name is optional.';
    }

    public function up(Schema $schema): void
    {
        // getUserIdentifier() has always returned the email and the firewall's
        // username_parameter is already `email`, so nothing authenticates by
        // this column; it survived as a unique column plus a lookup.
        $this->addSql('DROP INDEX IF EXISTS uniq_1483a5e9f85e0677');
        $this->addSql('ALTER TABLE users DROP COLUMN username');

        // Registration no longer asks for a name and nothing derives one, so
        // the column has to admit the accounts that will not have one.
        $this->addSql('ALTER TABLE users ALTER COLUMN full_name DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Irreversible in the part that matters: the usernames are gone, so a
        // rollback can only restore the shape. Existing rows are backfilled
        // from the email local part, uniquified by row number where two
        // addresses share one, because the column is UNIQUE and NOT NULL.
        // Only the shape comes back, not the old application invariant:
        // `foo.bar@x.com` yields `foo.bar`, which the `^[a-z][a-z0-9_-]*$`
        // rule the application (never the DB) enforced would have rejected.
        //
        // Partition and truncate on the same 30-char base, and reserve room
        // for the suffix inside it — numbering the full local part instead
        // lets two addresses that differ only past character 30 collide.
        $this->addSql('ALTER TABLE users ADD COLUMN username VARCHAR(30)');
        $this->addSql(<<<'SQL'
            UPDATE users SET username = sub.candidate FROM (
                SELECT id, CASE
                    WHEN rn = 1 THEN base
                    ELSE LEFT(base, 30 - length(rn::text)) || rn::text
                END AS candidate
                FROM (
                    SELECT id, LEFT(split_part(email, '@', 1), 30) AS base, ROW_NUMBER() OVER (
                        PARTITION BY LEFT(split_part(email, '@', 1), 30) ORDER BY created_at, id
                    ) AS rn
                    FROM users
                ) numbered
            ) sub
            WHERE users.id = sub.id
            SQL);
        $this->addSql('ALTER TABLE users ALTER COLUMN username SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_1483a5e9f85e0677 ON users (username)');

        // full_name has no safe backfill — the whole point of the change is
        // that it may legitimately be absent — so restoring NOT NULL would
        // fail on any account that never set one. Left nullable deliberately.
    }
}
