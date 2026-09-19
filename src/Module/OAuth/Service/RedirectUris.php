<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

/** What the consent page tells a user about where the code goes. */
final class RedirectUris
{
    /** The scheme, host and port of a redirect URI, or the URI without its query when it has no host. */
    public static function origin(string $uri): string
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['host'])) {
            return strtok($uri, '?#') ?: $uri;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.$parts['host'].$port;
    }

    /**
     * True when every URI sends the code back to the user's own machine. The
     * consent page then warns that it cannot vouch for the app behind it.
     *
     * @param array<array-key, mixed> $uris
     */
    public static function allLoopback(array $uris): bool
    {
        if ([] === $uris) {
            return false;
        }

        return array_all($uris, static fn (mixed $uri): bool => \is_string($uri) && self::isLoopback($uri));
    }

    private static function isLoopback(string $uri): bool
    {
        $host = strtolower(trim((string) parse_url($uri, \PHP_URL_HOST), '[]'));

        return 'localhost' === $host
            || '::1' === $host
            || (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) && str_starts_with($host, '127.'));
    }
}
