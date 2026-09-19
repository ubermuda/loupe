<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

/**
 * A client_id that is the URL of its Client ID Metadata Document. Only a
 * plain https URL on a DNS name passes: no userinfo, port, query, fragment,
 * dot segment or IP literal, so no numeric host form reaches the resolver.
 *
 * The bundle's client identifier column holds 32 characters, so a document
 * client is stored under a prefixed SHA-256 of its URL.
 */
final readonly class ClientIdUrl
{
    public const string IDENTIFIER_PREFIX = 'cimd-';
    public const int MAX_LENGTH = 255;

    private const string HOST = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';
    private const string PATH = '#^(/[A-Za-z0-9._~!$&\'()*+,;=:@%-]+)+/?$#';

    /** @var non-empty-string */
    public string $identifier;

    private function __construct(
        public string $url,
        public string $host,
    ) {
        $this->identifier = self::IDENTIFIER_PREFIX.substr(hash('sha256', $url), 0, 32 - \strlen(self::IDENTIFIER_PREFIX));
    }

    public static function isCandidate(string $clientId): bool
    {
        return 0 === strncasecmp($clientId, 'https://', 8);
    }

    public static function parse(string $clientId): ?self
    {
        if (\strlen($clientId) > self::MAX_LENGTH || !str_starts_with($clientId, 'https://')) {
            return null;
        }

        $rest = substr($clientId, \strlen('https://'));
        $slash = strpos($rest, '/');
        if (false === $slash) {
            return null;
        }

        $host = substr($rest, 0, $slash);
        $path = substr($rest, $slash);
        if (1 !== preg_match(self::HOST, $host) || 1 !== preg_match(self::PATH, $path)) {
            return null;
        }

        foreach (explode('/', rawurldecode($path)) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                return null;
            }
        }

        return new self($clientId, $host);
    }
}
