<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Service;

use App\Module\Account\Service\GithubPrimaryEmailFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GithubPrimaryEmailFetcherTest extends TestCase
{
    public function test_returns_the_primary_verified_email(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            ['email' => 'other@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'octo@example.com', 'primary' => true, 'verified' => true],
        ]));

        $result = new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok');

        self::assertSame(['email' => 'octo@example.com', 'verified' => true], $result);
    }

    public function test_unverified_primary_is_reported_unverified(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            ['email' => 'octo@example.com', 'primary' => true, 'verified' => false],
        ]));

        self::assertSame(
            ['email' => 'octo@example.com', 'verified' => false],
            new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok'),
        );
    }

    public function test_no_primary_entry_degrades_to_null(): void
    {
        $client = new MockHttpClient(new JsonMockResponse([
            ['email' => 'other@example.com', 'primary' => false, 'verified' => true],
        ]));

        self::assertNull(new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok'));
    }

    public function test_http_failure_degrades_to_null(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 500]));

        self::assertNull(new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok'));
    }

    public function test_revoked_token_degrades_to_null(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 401]));

        self::assertNull(new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok'));
    }

    public function test_non_json_body_degrades_to_null(): void
    {
        $client = new MockHttpClient(new MockResponse('not json', ['response_headers' => ['content-type' => 'text/plain']]));

        self::assertNull(new GithubPrimaryEmailFetcher($client)->fetchPrimary('tok'));
    }

    public function test_sends_the_bearer_token_to_the_user_emails_endpoint(): void
    {
        $method = null;
        $url = null;
        $headers = [];
        $client = new MockHttpClient(
            /** @param array<string, mixed> $options */
            function (string $requestMethod, string $requestUrl, array $options) use (&$method, &$url, &$headers): JsonMockResponse {
                $method = $requestMethod;
                $url = $requestUrl;
                $headers = $options['headers'] ?? [];

                return new JsonMockResponse([['email' => 'octo@example.com', 'primary' => true, 'verified' => true]]);
            },
            // The host is the scoped github.api_client's base_uri, not something
            // the fetcher builds — it asks for a path and the bounded client
            // supplies the rest. Passing it here keeps the assertion below
            // checking the join rather than a hardcoded literal.
            'https://api.github.com',
        );

        new GithubPrimaryEmailFetcher($client)->fetchPrimary('secret-token');

        self::assertSame('GET', $method);
        self::assertSame('https://api.github.com/user/emails', $url);
        self::assertStringContainsString('Bearer secret-token', json_encode($headers, \JSON_THROW_ON_ERROR));
    }
}
