<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use App\Module\OAuth\Grant\LoopbackRedirectUri;

/**
 * The parts of a Client ID Metadata Document Loupe uses, after validation.
 * Only a public client with the code grant passes. A redirect URI must be
 * https, or http on a loopback address for a native client.
 */
final readonly class FetchedClientMetadata
{
    public const int MAX_NAME_LENGTH = 128;
    private const int MAX_REDIRECT_URIS = 10;
    private const int MAX_REDIRECT_URI_LENGTH = 512;

    /** @param non-empty-list<non-empty-string> $redirectUris */
    private function __construct(
        public string $clientName,
        public array $redirectUris,
        public int $maxAge,
    ) {
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @throws ClientMetadataRefused
     */
    public static function fromDocument(ClientIdUrl $url, array $document, int $maxAge): self
    {
        if (($document['client_id'] ?? null) !== $url->url) {
            throw new ClientMetadataRefused('The client_id in the client metadata document is not its URL.');
        }

        if ('none' !== ($document['token_endpoint_auth_method'] ?? null) || \array_key_exists('client_secret', $document)) {
            throw new ClientMetadataRefused('A client metadata document must describe a public client, with token_endpoint_auth_method "none".');
        }

        $grantTypes = $document['grant_types'] ?? ['authorization_code'];
        $responseTypes = $document['response_types'] ?? ['code'];
        if (!\is_array($grantTypes) || !\in_array('authorization_code', $grantTypes, true) || !\is_array($responseTypes) || !\in_array('code', $responseTypes, true)) {
            throw new ClientMetadataRefused('The client metadata document must allow the authorization_code grant.');
        }

        return new self(self::name($document['client_name'] ?? null, $url->host), self::redirectUris($document['redirect_uris'] ?? null), $maxAge);
    }

    /** @return non-empty-list<non-empty-string> */
    private static function redirectUris(mixed $uris): array
    {
        if (!\is_array($uris) || [] === $uris || \count($uris) > self::MAX_REDIRECT_URIS || !array_is_list($uris)) {
            throw new ClientMetadataRefused('The client metadata document must list between one and ten redirect_uris.');
        }

        $valid = [];
        foreach ($uris as $uri) {
            if (!\is_string($uri) || '' === $uri || \strlen($uri) > self::MAX_REDIRECT_URI_LENGTH || !self::isAllowedRedirectUri($uri)) {
                throw new ClientMetadataRefused('Each redirect URI must be https, or http on a loopback address.');
            }
            $valid[] = $uri;
        }

        return $valid;
    }

    private static function isAllowedRedirectUri(string $uri): bool
    {
        $parts = parse_url($uri);
        if (false === $parts || !isset($parts['host']) || false === filter_var($uri, \FILTER_VALIDATE_URL) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || 1 !== preg_match('/^[\x21-\x7e]+$/', $uri) || str_contains($uri, '#')) {
            return false;
        }

        return match ($parts['scheme'] ?? null) {
            'https' => true,
            'http' => LoopbackRedirectUri::isLoopback($uri),
            default => false,
        };
    }

    private static function name(mixed $name, string $host): string
    {
        $name = \is_string($name) ? trim((string) preg_replace('/[\p{C}]+/u', ' ', $name)) : '';

        return '' === $name ? $host : mb_substr($name, 0, self::MAX_NAME_LENGTH);
    }
}
