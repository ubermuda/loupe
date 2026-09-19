<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a Client ID Metadata Document with SSRF guards. Every address of
 * the host must be public, and the connection goes to the vetted address
 * with no second lookup, so DNS rebinding cannot swap it. Redirects and
 * proxies are off, and the body is capped.
 */
final readonly class ClientMetadataFetcher
{
    public const int MAX_BYTES = 5120;

    public function __construct(
        #[Autowire(service: 'oauth.client_metadata_client')]
        private HttpClientInterface $http,
        private HostResolver $resolver,
    ) {
    }

    /** @throws ClientMetadataRefused */
    public function fetch(ClientIdUrl $url): FetchedClientMetadata
    {
        $addresses = $this->resolver->resolve($url->host);
        if ([] === $addresses || !array_all($addresses, PublicAddressPolicy::isPublic(...))) {
            throw new ClientMetadataRefused('The client metadata host does not resolve to public addresses only.');
        }

        $ip = array_find($addresses, static fn (string $address): bool => false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) ?? $addresses[0];

        try {
            $response = $this->http->request('GET', $url->url, [
                'resolve' => [$url->host => $ip],
                'max_redirects' => 0,
                'no_proxy' => '*',
                'headers' => ['Accept' => 'application/json'],
                'on_progress' => static function (int $downloaded, int $size, array $info) use ($ip): void {
                    $connected = trim((string) ($info['primary_ip'] ?? ''), '[]');
                    if (('' !== $connected && $connected !== $ip) || $size > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                        throw new ClientMetadataRefused('The client metadata fetch left its bounds.');
                    }
                },
            ]);

            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isFirst() && 200 !== $response->getStatusCode()) {
                    $response->cancel();

                    throw new ClientMetadataRefused(\sprintf('The client metadata document answered HTTP %d.', $response->getStatusCode()));
                }

                $body .= $chunk->getContent();
                if (\strlen($body) > self::MAX_BYTES) {
                    $response->cancel();

                    throw new ClientMetadataRefused('The client metadata document is too large.');
                }
            }

            $headers = $response->getHeaders(false);
        } catch (ExceptionInterface $e) {
            throw new ClientMetadataRefused('The client metadata document could not be fetched.', 0, $e);
        }

        $contentType = strtolower(trim((string) strtok($headers['content-type'][0] ?? '', ';')));
        if ('application/json' !== $contentType) {
            throw new ClientMetadataRefused('The client metadata document is not JSON.');
        }

        try {
            $document = json_decode($body, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ClientMetadataRefused('The client metadata document is not valid JSON.', 0, $e);
        }

        if (!\is_array($document) || array_is_list($document)) {
            throw new ClientMetadataRefused('The client metadata document is not a JSON object.');
        }

        return FetchedClientMetadata::fromDocument($url, $document, self::maxAge(implode(',', $headers['cache-control'] ?? [])));
    }

    /** Seconds to trust the document: its max-age, held between five minutes and a day. */
    private static function maxAge(string $cacheControl): int
    {
        $directives = strtolower($cacheControl);
        if (str_contains($directives, 'no-store') || str_contains($directives, 'no-cache')) {
            return 300;
        }

        if (1 !== preg_match('/(?:^|[\s,])max-age=(\d+)/', $directives, $matches)) {
            return 3600;
        }

        return max(300, min(86400, (int) $matches[1]));
    }
}
