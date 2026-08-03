<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Account\Entity\User;
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
        // The values are spelled out rather than read from AgentAccountInstaller,
        // which writes the same row for the e2e reset: a migration must produce
        // the same data on every database that has ever run it, and sharing the
        // constants would let a later edit to them silently diverge an
        // already-migrated environment from a fresh one.
        //
        // No password and no roles: nothing can authenticate as it. The dot in
        // the username puts it out of reach of registration, which accepts
        // [a-z][a-z0-9_-]* only, and `.invalid` is reserved by IANA.
        $this->addSql(<<<'SQL'
            INSERT INTO users (id, roles, username, full_name, email, password, created_at)
            VALUES (:id, '[]', 'loupe.agent', 'Agent', 'agent@loupe.invalid', NULL, now())
            ON CONFLICT (id) DO NOTHING
            SQL, ['id' => User::AGENT_ID]);
    }

    public function down(Schema $schema): void
    {
        // Comments the agent authored reference this row, so they go first —
        // comments.author_id has no ON DELETE clause.
        $this->addSql('DELETE FROM comments WHERE author_id = :id', ['id' => User::AGENT_ID]);
        $this->addSql('DELETE FROM users WHERE id = :id', ['id' => User::AGENT_ID]);
    }
}
