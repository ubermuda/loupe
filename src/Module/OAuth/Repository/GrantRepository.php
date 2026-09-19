<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use App\Module\OAuth\Scope\GrantedScope;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Reads and writes the bundle's token tables. A refresh token row holds no user
 * and no scope, only a link to its access token, and deleting that access token
 * sets the link to null. So every write reaches the refresh tokens first, and
 * no access token is deleted while a refresh token still points at it.
 */
final readonly class GrantRepository
{
    private const string LIVE_ACCESS_TOKENS = <<<'SQL'
        SELECT a.identifier FROM oauth2_access_token a
        WHERE a.user_identifier = :userId
          AND (
            (a.revoked = false AND a.expiry > NOW())
            OR EXISTS (SELECT 1 FROM oauth2_refresh_token r WHERE r.access_token = a.identifier AND r.revoked = false AND r.expiry > NOW())
          )
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * One row per client and scope set that still holds a usable token.
     *
     * @return list<array{clientId: string, clientName: string, scopes: string}>
     */
    public function findLiveGrantsForUser(Uuid $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.identifier AS "clientId", c.name AS "clientName", COALESCE(a.scopes, \'\') AS scopes
             FROM oauth2_access_token a JOIN oauth2_client c ON c.identifier = a.client
             WHERE a.identifier IN ('.self::LIVE_ACCESS_TOKENS.')
             GROUP BY c.identifier, c.name, a.scopes
             ORDER BY c.name, a.scopes',
            ['userId' => $userId->toRfc4122()],
        );

        return array_map(static fn (array $row): array => [
            'clientId' => trim((string) $row['clientId']),
            'clientName' => (string) $row['clientName'],
            'scopes' => (string) $row['scopes'],
        ], $rows);
    }

    /** Revokes every access token, refresh token, code and device code the client holds for the user. */
    public function revokeForUserAndClient(Uuid $userId, string $clientId): int
    {
        $parameters = ['userId' => $userId->toRfc4122(), 'clientId' => $clientId];

        return $this->connection->transactional(function (Connection $connection) use ($parameters): int {
            $connection->executeStatement(
                'UPDATE oauth2_refresh_token SET revoked = true WHERE revoked = false AND access_token IN
                 (SELECT identifier FROM oauth2_access_token WHERE user_identifier = :userId AND client = :clientId)',
                $parameters,
            );
            $connection->executeStatement(
                'UPDATE oauth2_authorization_code SET revoked = true WHERE revoked = false AND user_identifier = :userId AND client = :clientId',
                $parameters,
            );
            $connection->executeStatement(
                'UPDATE oauth2_device_code SET revoked = true WHERE revoked = false AND user_identifier = :userId AND client = :clientId',
                $parameters,
            );

            return (int) $connection->executeStatement(
                'UPDATE oauth2_access_token SET revoked = true WHERE revoked = false AND user_identifier = :userId AND client = :clientId',
                $parameters,
            );
        });
    }

    /**
     * Deletes expired rows. The bundle's own clearExpired() deletes an expired
     * access token even while a live refresh token points at it, which leaves
     * that refresh token with no user to revoke it by.
     */
    public function deleteExpired(): int
    {
        return $this->connection->transactional(static fn (Connection $connection): int => (int) $connection->executeStatement('DELETE FROM oauth2_refresh_token WHERE expiry < NOW()')
            + (int) $connection->executeStatement('DELETE FROM oauth2_access_token a WHERE a.expiry < NOW() AND NOT EXISTS (SELECT 1 FROM oauth2_refresh_token r WHERE r.access_token = a.identifier)')
            + (int) $connection->executeStatement('DELETE FROM oauth2_authorization_code WHERE expiry < NOW()')
            + (int) $connection->executeStatement('DELETE FROM oauth2_device_code WHERE expiry < NOW()'));
    }

    public function deleteForUser(Uuid $userId): void
    {
        $this->deleteWhere('user_identifier = :value', $userId->toRfc4122());
    }

    /** Deletes every grant whose scopes name the project. */
    public function deleteForProject(Uuid $projectId): void
    {
        $this->deleteWhere('(\' \' || scopes || \' \') LIKE :value', '% '.GrantedScope::projectScope($projectId).' %');
    }

    private function deleteWhere(string $condition, string $value): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($condition, $value): void {
            $connection->executeStatement(
                'DELETE FROM oauth2_refresh_token WHERE access_token IN (SELECT identifier FROM oauth2_access_token WHERE '.$condition.')',
                ['value' => $value],
            );
            $connection->executeStatement('DELETE FROM oauth2_access_token WHERE '.$condition, ['value' => $value]);
            $connection->executeStatement('DELETE FROM oauth2_authorization_code WHERE '.$condition, ['value' => $value]);
            $connection->executeStatement('DELETE FROM oauth2_device_code WHERE '.$condition, ['value' => $value]);
        });
    }
}
