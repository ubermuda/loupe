<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use Symfony\Component\HttpFoundation\IpUtils;

/** The addresses a client metadata fetch may connect to: public unicast only. */
final class PublicAddressPolicy
{
    private const array ALSO_REFUSED = [
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '100::/64',
        '2001:db8::/32',
        'fec0::/10',
        'ff00::/8',
    ];

    public static function isPublic(string $ip): bool
    {
        if (false === filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        return !IpUtils::checkIp($ip, [...IpUtils::PRIVATE_SUBNETS, ...self::ALSO_REFUSED]);
    }
}
