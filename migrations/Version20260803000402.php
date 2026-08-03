<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Module\Account\Entity\User;
use App\Module\Account\Service\AgentAccountInstaller;
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
        AgentAccountInstaller::install($this->connection);
    }

    public function down(Schema $schema): void
    {
        // Comments the agent authored reference this row, so they go first —
        // comments.author_id has no ON DELETE clause.
        $this->addSql('DELETE FROM comments WHERE author_id = :id', ['id' => User::AGENT_ID]);
        $this->addSql('DELETE FROM users WHERE id = :id', ['id' => User::AGENT_ID]);
    }
}
