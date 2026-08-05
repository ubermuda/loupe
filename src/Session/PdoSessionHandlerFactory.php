<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the session handler against the database Doctrine actually connected
 * to, which is not the one named in DATABASE_URL.
 *
 * `doctrine.yaml` appends a `dbname_suffix` — `WORKTREE_DB_SUFFIX` per worktree,
 * `_test<TEST_TOKEN>` under PHPUnit — so the raw URL names the main `app`
 * database while the `sessions` table was migrated into the suffixed one. Using
 * the URL as-is makes every worktree read and write the main database's
 * sessions, which silently shares logins across worktrees rather than failing.
 *
 * The handler still gets a DSN rather than the Connection: it then opens its own
 * connection, so writing a session never joins a transaction the request already
 * has open and cannot be rolled back with it, and it connects lazily, so a
 * request that never touches the session opens nothing.
 */
final readonly class PdoSessionHandlerFactory
{
    public function __construct(
        private Connection $connection,

        #[Autowire(env: 'DATABASE_URL')]
        private string $databaseUrl,
    ) {
    }

    public function __invoke(): PdoSessionHandler
    {
        return new PdoSessionHandler($this->sessionUrl());
    }

    /**
     * DATABASE_URL with its database name replaced by the one Doctrine resolved.
     */
    public function sessionUrl(): string
    {
        $parts = parse_url($this->databaseUrl);

        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            throw new \LogicException('DATABASE_URL is not a URL the session handler can read.');
        }

        // Only the database name is taken from Doctrine; everything else comes
        // from the URL, so the handler keeps whatever driver it names.
        $url = $parts['scheme'].'://';

        if (isset($parts['user'])) {
            $url .= $parts['user'];
            $url .= isset($parts['pass']) ? ':'.$parts['pass'] : '';
            $url .= '@';
        }

        $url .= $parts['host'];
        $url .= isset($parts['port']) ? ':'.$parts['port'] : '';

        return $url.'/'.rawurlencode($this->connection->getDatabase() ?? '');
    }
}
