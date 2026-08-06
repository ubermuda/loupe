<?php

declare(strict_types=1);

namespace App\Session;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

/**
 * Builds the session handler's own connection, which differs from DATABASE_URL
 * in three ways that each fail quietly if you undo them.
 *
 * The database name comes from Doctrine, not the URL: `dbname_suffix` appends
 * WORKTREE_DB_SUFFIX and _test<TEST_TOKEN>, so the raw URL names the main `app`
 * database — where `sessions` also exists, so a worktree shares main's logins
 * rather than erroring.
 *
 * A DSN, not a URL: PdoSessionHandler's URL parser drops the query string for
 * pgsql, so `sslmode` never reaches the connection.
 *
 * Its own connection, not Doctrine's PDO: it takes a transactional lock while
 * reading a session, which would nest inside the request's transaction.
 */
final readonly class PdoSessionHandlerFactory
{
    /**
     * libpq keys worth carrying from DATABASE_URL onto the session connection.
     * Doctrine-only parameters (`serverVersion`, `charset`) are not libpq
     * keywords and would make the connection fail.
     *
     * @var list<string>
     */
    private const array FORWARDED_PARAMETERS = [
        'sslmode',
        'sslcert',
        'sslkey',
        'sslrootcert',
        'sslcrl',
        'application_name',
        'connect_timeout',
    ];

    public function __construct(
        private Connection $connection,

        #[Autowire(env: 'DATABASE_URL')]
        private string $databaseUrl,
    ) {
    }

    public function __invoke(): PdoSessionHandler
    {
        $parts = $this->urlParts();

        return new PdoSessionHandler($this->sessionDsn(), [
            'db_username' => rawurldecode($parts['user'] ?? ''),
            'db_password' => rawurldecode($parts['pass'] ?? ''),
        ]);
    }

    /**
     * A pgsql PDO DSN for the database Doctrine connected to.
     */
    public function sessionDsn(): string
    {
        $parts = $this->urlParts();

        $dsn = 'pgsql:';

        if (isset($parts['host']) && '' !== $parts['host']) {
            $dsn .= 'host='.$parts['host'].';';
        }

        if (isset($parts['port'])) {
            $dsn .= 'port='.$parts['port'].';';
        }

        $dsn .= 'dbname='.($this->connection->getDatabase() ?? '').';';

        parse_str($parts['query'] ?? '', $query);

        foreach (self::FORWARDED_PARAMETERS as $name) {
            $value = $query[$name] ?? null;

            if (is_string($value) && '' !== $value) {
                $dsn .= $name.'='.$value.';';
            }
        }

        return rtrim($dsn, ';');
    }

    /**
     * @return array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, query?: string}
     */
    private function urlParts(): array
    {
        $parts = parse_url($this->databaseUrl);

        if (false === $parts || !isset($parts['scheme'])) {
            throw new \LogicException('DATABASE_URL is not a URL the session handler can read.');
        }

        // Postgres is a hard dependency of this application and the DSN above is
        // libpq-shaped, so fail loudly rather than emit a pgsql DSN for
        // something else.
        if (!in_array($parts['scheme'], ['postgresql', 'postgres', 'pdo-pgsql', 'pdo_pgsql'], true)) {
            throw new \LogicException(sprintf('The session handler expects a Postgres DATABASE_URL, got scheme "%s".', $parts['scheme']));
        }

        return $parts;
    }
}
