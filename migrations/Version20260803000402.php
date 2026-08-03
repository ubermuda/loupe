<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803000402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Insert the singleton agent user that authors agent-written comments.';
    }

    public function up(Schema $schema): void
    {
        // Inserted here rather than created on first use: every environment runs
        // migrations (including the PHPUnit bootstrap), and a get-or-create
        // inside a tool call would make the first agent reply in a fresh
        // install a users-table write inside a request that may roll back.
        //
        // Every value is a literal, including the id — no App class is imported.
        // A migration must write the same row on every database that has ever
        // run it, so it cannot depend on a constant a later commit could change;
        // and an import would make `migrations:migrate` fatal on a fresh
        // database the day that class is renamed or moved. The runtime copy of
        // this id is App\Module\Account\Entity\User::AGENT_ID, and every
        // comments.author_id written by an agent points at it.
        //
        // No password and no roles: nothing can authenticate as it. The dot in
        // the username puts it out of reach of registration, which accepts
        // [a-z][a-z0-9_-]* only, and `.invalid` is reserved by IANA.
        //
        // The conflict target is deliberate. A bare ON CONFLICT DO NOTHING also
        // swallows the username and email unique violations, so an installation
        // where a person already holds one of those identities would migrate
        // "successfully" with no agent row at all, and every agent reply would
        // then fail far from the cause. Re-running is a no-op because the id
        // conflicts; a clash on any other column is a real data conflict that
        // needs a human, so it must abort the migration loudly.
        $this->addSql(<<<'SQL'
            INSERT INTO users (id, roles, username, full_name, email, password, created_at)
            VALUES ('1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9', '[]', 'loupe.agent', 'Agent', 'agent@loupe.invalid', NULL, now())
            ON CONFLICT (id) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Comments the agent authored reference this row, so they go first —
        // comments.author_id has no ON DELETE clause.
        $this->addSql("DELETE FROM comments WHERE author_id = '1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9'");
        $this->addSql("DELETE FROM users WHERE id = '1073e0a5-9b1c-42f7-8e44-a10a6e57c3d9'");
    }
}
