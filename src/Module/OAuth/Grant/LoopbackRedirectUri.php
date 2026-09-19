<?php

declare(strict_types=1);

namespace App\Module\OAuth\Grant;

/**
 * Redirect URI matching. A native client listens on a port the system picks,
 * so an http loopback URI matches a registered one on any port (RFC 8252,
 * section 7.3). League does this for the IP literals only; localhost is added
 * because Claude Code registers http://localhost/callback.
 */
final class LoopbackRedirectUri
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** @param array<array-key, mixed> $registered */
    public static function isRegistered(string $requested, array $registered): bool
    {
        if (\in_array($requested, $registered, true)) {
            return true;
        }

        $key = self::loopbackKey($requested);

        return null !== $key && array_any($registered, static fn (mixed $uri): bool => \is_string($uri) && self::loopbackKey($uri) === $key);
    }

    public static function isLoopback(string $uri): bool
    {
        return null !== self::loopbackKey($uri);
    }

    /** The URI without its port, or null when it is not a plain http loopback URI. */
    private static function loopbackKey(string $uri): ?string
    {
        $parts = parse_url($uri);
        if (false === $parts || 'http' !== ($parts['scheme'] ?? null) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || str_contains($uri, '#')) {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');
        if (!\in_array($host, self::LOOPBACK_HOSTS, true)) {
            return null;
        }

        return 'http://'.$host.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
