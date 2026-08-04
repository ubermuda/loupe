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
        // No password and no roles: nothing can authenticate as it. What puts
        // it out of reach of registration is the address: `.invalid` is
        // reserved by IANA precisely so it can never resolve, so no verification
        // mail could ever be received there.
        //
        // The conflict target is deliberate. A bare ON CONFLICT DO NOTHING also
        // swallows the email unique violation, so an account already holding
        // that address would leave this a no-op and the app with no agent row —
        // surfacing later as a failure deep inside a reply. Only a repeat of the
        // id itself is the idempotency wanted here; anything else is a real
        // conflict and must raise.
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
