<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;

/** Writes device code rows for the loupe-cli client that the migrations register. */
final class DeviceCodeRows
{
    public static function insert(Connection $connection, string $identifier, string $userCode, string $expiry = '+10 minutes', bool $revoked = false, ?string $userId = null): void
    {
        $connection->insert('oauth2_device_code', [
            'identifier' => $identifier,
            'expiry' => new \DateTimeImmutable($expiry)->format('Y-m-d H:i:s'),
            'user_identifier' => $userId,
            'scopes' => 'agent',
            'revoked' => $revoked,
            'user_code' => $userCode,
            'user_approved' => false,
            'include_verification_uri_complete' => true,
            'verification_uri' => 'http://localhost/oauth/device',
            '"interval"' => 5,
            'client' => 'loupe-cli',
        ], ['revoked' => 'boolean', 'user_approved' => 'boolean', 'include_verification_uri_complete' => 'boolean']);
    }
}
