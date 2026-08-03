<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Restores the singleton agent account after something has wiped `users`.
 *
 * SQL rather than the ORM because the row's id is fixed and `User::$id` is
 * write-protected — Doctrine would mint a new one. The migration that first
 * installs the account deliberately spells the same values out instead of
 * calling this: a migration must write identical data on every database that
 * has ever run it, which a shared constant cannot promise.
 */
final class AgentAccountInstaller
{
    public static function install(Connection $connection): void
    {
        // No password and no roles: nothing can authenticate as it. The dot in
        // the username puts it out of reach of registration, which accepts
        // [a-z][a-z0-9_-]* only, and `.invalid` is reserved by IANA.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, roles, username, full_name, email, password, created_at)
                VALUES (:id, '[]', 'loupe.agent', 'Agent', 'agent@loupe.invalid', NULL, now())
                ON CONFLICT DO NOTHING
                SQL,
            ['id' => User::AGENT_ID],
        );
    }
}
