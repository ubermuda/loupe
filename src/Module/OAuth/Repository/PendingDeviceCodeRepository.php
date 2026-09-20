<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads and answers the bundle's device codes. League completes a code by id
 * and checks neither its expiry nor an earlier answer, so the lookup and the
 * answer here both carry those conditions.
 */
final readonly class PendingDeviceCodeRepository
{
    private const string PENDING = 'd.revoked = false AND d.user_identifier IS NULL AND d.expiry > NOW()';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /** Null when no code matches, and also when two do, so no one approves a code they cannot tell apart. */
    public function findPending(string $userCode): ?PendingDeviceCode
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT d.identifier, d.client, c.name, COALESCE(d.scopes, \'\') AS scopes
             FROM oauth2_device_code d JOIN oauth2_client c ON c.identifier = d.client
             WHERE d.user_code = :userCode AND '.self::PENDING.'
             LIMIT 2',
            ['userCode' => $userCode],
        );
        if (1 !== \count($rows)) {
            return null;
        }

        $row = $rows[0];

        return new PendingDeviceCode(
            identifier: trim((string) $row['identifier']),
            userCode: $userCode,
            clientId: trim((string) $row['client']),
            clientName: (string) $row['name'],
            scopes: array_values(array_filter(explode(' ', (string) $row['scopes']), static fn (string $scope): bool => '' !== $scope)),
        );
    }

    /** False when the code expired or got its answer since it was read. */
    public function answer(string $identifier, string $userId, bool $approved): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE oauth2_device_code d SET user_identifier = :userId, user_approved = :approved
             WHERE d.identifier = :identifier AND '.self::PENDING,
            ['identifier' => $identifier, 'userId' => $userId, 'approved' => $approved],
            ['approved' => ParameterType::BOOLEAN],
        );
    }
}
