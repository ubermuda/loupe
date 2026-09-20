<?php

declare(strict_types=1);

namespace App\Module\OAuth\ClientMetadata;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a Client ID Metadata Document, and the icon of the host it came
 * from, with SSRF guards. Every address of the host must be public, and the
 * connection goes to the vetted address with no second lookup, so DNS
 * rebinding cannot swap it. Redirects and proxies are off, and the body is
 * capped.
 */
final readonly class ClientMetadataFetcher
{
    public const int MAX_BYTES = 5120;
    public const int MAX_ICON_BYTES = 32768;

    /** No SVG: it can carry script, and it would run on Loupe's own origin. */
    private const array ICON_TYPES = ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/gif', 'image/jpeg', 'image/webp'];

    public function __construct(
        #[Autowire(service: 'oauth.client_metadata_client')]
        private HttpClientInterface $http,
        private HostResolver $resolver,
    ) {
    }

    /** @throws ClientMetadataRefused */
    public function fetch(ClientIdUrl $url): FetchedClientMetadata
    {
        [$body, $headers] = $this->get($url->url, $url->host, self::MAX_BYTES, 'application/json', 'The client metadata document');

        $contentType = self::contentType($headers);
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

    /**
     * The icon of the host that published the document, which is the one
     * identity Loupe checked. A logo_uri in the document names any host the
     * client likes, so it is never fetched. Null when there is no usable icon:
     * the consent page then shows what it shows today.
     */
    public function fetchIcon(ClientIdUrl $url): ?FetchedIcon
    {
        try {
            [$body, $headers] = $this->get('https://'.$url->host.'/favicon.ico', $url->host, self::MAX_ICON_BYTES, 'image/*', 'The client icon');
        } catch (ClientMetadataRefused) {
            return null;
        }

        $contentType = self::contentType($headers);

        return '' !== $body && \in_array($contentType, self::ICON_TYPES, true) ? new FetchedIcon($body, $contentType) : null;
    }

    /**
     * @return array{string, array<string, list<string>>}
     *
     * @throws ClientMetadataRefused
     */
    private function get(string $url, string $host, int $maxBytes, string $accept, string $subject): array
    {
        $addresses = $this->resolver->resolve($host);
        if ([] === $addresses || !array_all($addresses, PublicAddressPolicy::isPublic(...))) {
            throw new ClientMetadataRefused('The client metadata host does not resolve to public addresses only.');
        }

        $ip = array_find($addresses, static fn (string $address): bool => false !== filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) ?? $addresses[0];

        try {
            $response = $this->http->request('GET', $url, [
                'resolve' => [$host => $ip],
                'max_redirects' => 0,
                'no_proxy' => '*',
                'headers' => ['Accept' => $accept],
                'on_progress' => static function (int $downloaded, int $size, array $info) use ($ip, $maxBytes): void {
                    $connected = trim((string) ($info['primary_ip'] ?? ''), '[]');
                    if (('' !== $connected && $connected !== $ip) || $size > $maxBytes || $downloaded > $maxBytes) {
                        throw new ClientMetadataRefused('The client metadata fetch left its bounds.');
                    }
                },
            ]);

            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                if ($chunk->isFirst() && 200 !== $response->getStatusCode()) {
                    $response->cancel();

                    throw new ClientMetadataRefused(\sprintf('%s answered HTTP %d.', $subject, $response->getStatusCode()));
                }

                $body .= $chunk->getContent();
                if (\strlen($body) > $maxBytes) {
                    $response->cancel();

                    throw new ClientMetadataRefused(\sprintf('%s is too large.', $subject));
                }
            }

            return [$body, $response->getHeaders(false)];
        } catch (ExceptionInterface $e) {
            throw new ClientMetadataRefused(\sprintf('%s could not be fetched.', $subject), 0, $e);
        }
    }

    /** @param array<string, list<string>> $headers */
    private static function contentType(array $headers): string
    {
        return strtolower(trim((string) strtok($headers['content-type'][0] ?? '', ';')));
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
