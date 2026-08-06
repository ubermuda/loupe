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
        // No password, no roles, and an IANA-reserved `.invalid` address that can
        // never receive verification mail — nothing can authenticate as it.
        //
        // The conflict target is deliberate: a bare DO NOTHING would swallow the
        // email unique violation too, leaving the app with no agent row. Only a
        // repeat of the id is idempotent here; anything else must raise.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, roles, full_name, email, password, created_at)
                VALUES (:id, '[]', 'Agent', 'agent@loupe.invalid', NULL, now())
                ON CONFLICT (id) DO NOTHING
                SQL,
            ['id' => User::AGENT_ID],
        );
    }
}
