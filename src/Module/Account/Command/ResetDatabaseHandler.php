<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Service\AgentAccountInstaller;
use Doctrine\DBAL\Connection;

final readonly class ResetDatabaseHandler
{
    public function __construct(
        private Connection $conn,
    ) {
    }

    public function __invoke(ResetDatabaseCommand $command): void
    {
        // Every application table is wiped; the list is derived from the live
        // schema so newly added tables can never silently escape the reset.
        /** @var list<string> $tables */
        $tables = $this->conn->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'",
        );

        if ([] !== $tables) {
            $quoted = array_map($this->conn->quoteIdentifier(...), $tables);
            $this->conn->executeStatement('TRUNCATE TABLE '.implode(', ', $quoted).' CASCADE');
        }

        // The agent account is schema, not data: a migration installs it and
        // every agent-written comment points at it. The truncate above takes it
        // with the rest of `users`, so it is put back. It does not reopen the
        // install wizard — that asks for human accounts (UserRepository::countHumans).
        AgentAccountInstaller::install($this->conn);
    }
}
