<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\ClientMetadata;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\ClientMetadataFetcher;
use App\Module\OAuth\ClientMetadata\ClientMetadataRefused;
use App\Tests\Support\FakeHostResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClientMetadataFetcherTest extends TestCase
{
    private const string URL = 'https://client.example/oauth/metadata';
    private const string PUBLIC_IP = '160.79.104.10';

    public function test_it_fetches_from_the_vetted_address_and_validates_the_document(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'resolve' => $options['resolve'] ?? null, 'max_redirects' => $options['max_redirects'] ?? null];

            return self::json(self::document(), ['cache-control' => 'public, max-age=900']);
        });

        $fetched = $this->fetcher($http)->fetch($this->url());

        self::assertSame('GET', $seen['method']);
        self::assertSame(self::URL, $seen['url']);
        self::assertSame(['client.example' => self::PUBLIC_IP], $seen['resolve']);
        self::assertSame(0, $seen['max_redirects']);
        self::assertSame('Example Agent', $fetched->clientName);
        self::assertSame(['http://localhost/callback', 'https://client.example/cb'], $fetched->redirectUris);
        self::assertSame(900, $fetched->maxAge);
    }

    /** @param list<string> $addresses */
    #[DataProvider('unsafeAddresses')]
    public function test_it_refuses_a_host_that_resolves_to_a_non_public_address_before_any_request(array $addresses): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request may leave'));

        $this->expectException(ClientMetadataRefused::class);
        $this->fetcher($http, $addresses)->fetch($this->url());
    }

    /** @return iterable<string, array{list<string>}> */
    public static function unsafeAddresses(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'private' => [['10.0.0.5']];
        yield 'link-local metadata' => [['169.254.169.254']];
        yield 'cgnat' => [['100.64.1.1']];
        yield 'ipv6 unique local' => [['fd12::1']];
        yield 'ipv6 loopback' => [['::1']];
        yield 'one private among public' => [[self::PUBLIC_IP, '10.0.0.5']];
        yield 'nothing' => [[]];
    }

    #[DataProvider('badResponses')]
    public function test_it_refuses_a_bad_response(MockResponse $response): void
    {
        $this->expectException(ClientMetadataRefused::class);
        $this->fetcher(new MockHttpClient($response))->fetch($this->url());
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function badResponses(): iterable
    {
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/']])];
        yield 'not found' => [new MockResponse('{}', ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']])];
        yield 'oversized body' => [self::json([...self::document(), 'padding' => str_repeat('a', 6000)])];
        yield 'oversized body in chunks' => [new MockResponse((static function (): \Generator {
            for ($i = 0; $i < 10; ++$i) {
                yield str_repeat(' ', 1000);
            }
        })(), ['response_headers' => ['content-type' => 'application/json']])];
        yield 'bad json' => [new MockResponse('{"client_id":', ['response_headers' => ['content-type' => 'application/json']])];
        yield 'a json list' => [new MockResponse('[]', ['response_headers' => ['content-type' => 'application/json']])];
        yield 'html' => [new MockResponse('<html></html>', ['response_headers' => ['content-type' => 'text/html']])];
        yield 'transport error' => [new MockResponse('', ['error' => 'connection timed out'])];
        yield 'client_id mismatch' => [self::json([...self::document(), 'client_id' => 'https://evil.example/oauth/metadata'])];
        yield 'no client_id' => [self::json(array_diff_key(self::document(), ['client_id' => true]))];
        yield 'confidential client' => [self::json([...self::document(), 'token_endpoint_auth_method' => 'client_secret_basic'])];
        yield 'no auth method' => [self::json(array_diff_key(self::document(), ['token_endpoint_auth_method' => true]))];
        yield 'a client secret' => [self::json([...self::document(), 'client_secret' => 's3cret'])];
        yield 'no redirect uris' => [self::json(array_diff_key(self::document(), ['redirect_uris' => true]))];
        yield 'empty redirect uris' => [self::json([...self::document(), 'redirect_uris' => []])];
        yield 'plain http redirect' => [self::json([...self::document(), 'redirect_uris' => ['http://client.example/cb']])];
        yield 'custom scheme redirect' => [self::json([...self::document(), 'redirect_uris' => ['javascript:alert(1)']])];
        yield 'redirect the bundle cannot store' => [self::json([...self::document(), 'redirect_uris' => ['https://under_score.example/cb']])];
        yield 'redirect with fragment' => [self::json([...self::document(), 'redirect_uris' => ['https://client.example/cb#x']])];
        yield 'no code grant' => [self::json([...self::document(), 'grant_types' => ['client_credentials']])];
    }

    #[DataProvider('cacheLifetimes')]
    public function test_the_cache_lifetime_follows_cache_control_within_bounds(?string $cacheControl, int $expected): void
    {
        $headers = null === $cacheControl ? [] : ['cache-control' => $cacheControl];

        self::assertSame($expected, $this->fetcher(new MockHttpClient(self::json(self::document(), $headers)))->fetch($this->url())->maxAge);
    }

    /** @return iterable<string, array{?string, int}> */
    public static function cacheLifetimes(): iterable
    {
        yield 'absent' => [null, 3600];
        yield 'short' => ['max-age=10', 300];
        yield 'long' => ['public, max-age=9999999', 86400];
        yield 'no-store' => ['no-store', 300];
        yield 'no-cache' => ['no-cache, max-age=900', 300];
    }

    public function test_a_missing_client_name_falls_back_to_the_host(): void
    {
        $fetched = $this->fetcher(new MockHttpClient(self::json(array_diff_key(self::document(), ['client_name' => true]))))->fetch($this->url());

        self::assertSame('client.example', $fetched->clientName);
    }

    public function test_it_fetches_the_icon_of_the_verified_host(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['url' => $url, 'resolve' => $options['resolve'] ?? null, 'max_redirects' => $options['max_redirects'] ?? null];

            return new MockResponse('icon-bytes', ['response_headers' => ['content-type' => 'image/vnd.microsoft.icon']]);
        });

        $icon = $this->fetcher($http)->fetchIcon($this->url());

        self::assertSame('https://client.example/favicon.ico', $seen['url'], 'the icon comes from the host, never from a URL in the document');
        self::assertSame(['client.example' => self::PUBLIC_IP], $seen['resolve']);
        self::assertSame(0, $seen['max_redirects']);
        self::assertSame('image/vnd.microsoft.icon', $icon?->contentType);
        self::assertSame('icon-bytes', $icon->bytes);
    }

    #[DataProvider('unusableIcons')]
    public function test_an_unusable_icon_is_no_icon(MockResponse $response): void
    {
        self::assertNull($this->fetcher(new MockHttpClient($response))->fetchIcon($this->url()));
    }

    /** @return iterable<string, array{MockResponse}> */
    public static function unusableIcons(): iterable
    {
        yield 'not found' => [new MockResponse('', ['http_code' => 404, 'response_headers' => ['content-type' => 'image/png']])];
        yield 'redirect' => [new MockResponse('', ['http_code' => 302, 'response_headers' => ['location' => 'http://169.254.169.254/']])];
        yield 'transport error' => [new MockResponse('', ['error' => 'connection timed out'])];
        yield 'html' => [new MockResponse('<html></html>', ['response_headers' => ['content-type' => 'text/html']])];
        yield 'svg, which can carry script' => [new MockResponse('<svg/>', ['response_headers' => ['content-type' => 'image/svg+xml']])];
        yield 'no content type' => [new MockResponse('icon-bytes')];
        yield 'empty body' => [new MockResponse('', ['response_headers' => ['content-type' => 'image/png']])];
        yield 'oversized body' => [new MockResponse(str_repeat('a', ClientMetadataFetcher::MAX_ICON_BYTES + 1), ['response_headers' => ['content-type' => 'image/png']])];
    }

    public function test_an_unsafe_host_gets_no_icon_and_no_request(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('no request may leave'));

        self::assertNull($this->fetcher($http, ['127.0.0.1'])->fetchIcon($this->url()));
    }

    /** @param list<string> $addresses */
    private function fetcher(MockHttpClient $http, array $addresses = [self::PUBLIC_IP]): ClientMetadataFetcher
    {
        return new ClientMetadataFetcher($http, new FakeHostResolver(['client.example' => $addresses]));
    }

    private function url(): ClientIdUrl
    {
        return ClientIdUrl::parse(self::URL) ?? throw new \LogicException('test URL is valid');
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return [
            'client_id' => self::URL,
            'client_name' => 'Example Agent',
            'redirect_uris' => ['http://localhost/callback', 'https://client.example/cb'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private static function json(array $body, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['response_headers' => ['content-type' => 'application/json', ...$headers]]);
    }
}
