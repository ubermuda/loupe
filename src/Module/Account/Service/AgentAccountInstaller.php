<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Writes the singleton agent account.
 *
 * SQL rather than the ORM because the row's id is fixed and `User::$id` is
 * write-protected — Doctrine would mint a new one. Static because the migration
 * that first installs the account has a connection but no container.
 */
final class AgentAccountInstaller
{
    /**
     * The dot makes this unregisterable: the registration form accepts
     * `[a-z][a-z0-9_-]*` only, so no person can claim the name.
     */
    private const string USERNAME = 'loupe.agent';

    private const string FULL_NAME = 'Agent';

    /** `.invalid` is reserved by IANA, so the address can never receive mail. */
    private const string EMAIL = 'agent@loupe.invalid';

    public static function install(Connection $connection): void
    {
        // No password and no roles: nothing can authenticate as it.
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO users (id, roles, username, full_name, email, password, created_at)
                VALUES (:id, '[]', :username, :fullName, :email, NULL, now())
                ON CONFLICT (id) DO NOTHING
                SQL,
            [
                'id' => User::AGENT_ID,
                'username' => self::USERNAME,
                'fullName' => self::FULL_NAME,
                'email' => self::EMAIL,
            ],
        );
    }
}
