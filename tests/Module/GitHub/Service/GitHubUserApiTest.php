<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubUserApiTest extends TestCase
{
    private const string NOW = '2026-10-08 12:00:00';
    private const string SECRET_TOKEN = 'ghu_secret_token_value';

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $oauthRequests = [];

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $apiRequests = [];

    public function test_the_exchange_returns_the_token_set_with_its_expiry(): void
    {
        $api = $this->api([$this->json(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600])]);

        $tokens = $api->exchangeCode('the-code', 'the-verifier', 'https://loupe.test/cb');

        self::assertSame('at', $tokens->accessToken);
        self::assertSame('rt', $tokens->refreshToken);
        self::assertEquals(new \DateTimeImmutable('2026-10-08 20:00:00'), $tokens->accessTokenExpiresAt);
        self::assertEquals(new \DateTimeImmutable('2027-04-10 12:00:00'), $tokens->refreshTokenExpiresAt);
        self::assertTrue($tokens->canRefresh());
        parse_str((string) $this->oauthRequests[0]['options']['body'], $body);
        self::assertSame('the-code', $body['code']);
        self::assertSame('the-verifier', $body['code_verifier']);
        self::assertArrayNotHasKey('grant_type', $body);
    }

    public function test_a_token_that_does_not_expire_cannot_refresh(): void
    {
        $api = $this->api([$this->json(['access_token' => 'at', 'token_type' => 'bearer'])]);

        $tokens = $api->exchangeCode('c', 'v', 'https://loupe.test/cb');

        self::assertNull($tokens->refreshToken);
        self::assertNull($tokens->accessTokenExpiresAt);
        self::assertFalse($tokens->canRefresh());
    }

    public function test_the_refresh_grant_sends_the_refresh_token(): void
    {
        $api = $this->api([$this->json(['access_token' => 'at2', 'refresh_token' => 'rt2', 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600])]);

        $tokens = $api->refresh('rt1');

        self::assertSame('at2', $tokens->accessToken);
        parse_str((string) $this->oauthRequests[0]['options']['body'], $body);
        self::assertSame('refresh_token', $body['grant_type']);
        self::assertSame('rt1', $body['refresh_token']);
        self::assertSame('the-client-id', $body['client_id']);
    }

    public function test_a_refused_refresh_names_the_error_code_and_never_the_token(): void
    {
        $api = $this->api([$this->json(['error' => 'bad_refresh_token', 'error_description' => 'The refresh token passed is incorrect or expired.'])]);

        try {
            $api->refresh(self::SECRET_TOKEN);
            self::fail('A refused refresh must throw.');
        } catch (GitHubUserApiFailed $e) {
            self::assertSame('token_bad_refresh_token', $e->reason);
            self::assertStringNotContainsString(self::SECRET_TOKEN, $e->getMessage().$e->getTraceAsString());
        }
    }

    public function test_the_user_lookup_returns_id_and_login(): void
    {
        $api = $this->api(apiResponses: [$this->json(['id' => 583231, 'login' => 'octocat', 'type' => 'User'])]);

        $profile = $api->user('at');

        self::assertSame(583231, $profile->id);
        self::assertSame('octocat', $profile->login);
        self::assertSame('https://api.github.com/user', $this->apiRequests[0]['url']);
        self::assertContains('Authorization: Bearer at', $this->apiRequests[0]['options']['headers']);
    }

    public function test_a_user_body_without_a_login_is_malformed(): void
    {
        $api = $this->api(apiResponses: [$this->json(['id' => 1])]);

        $this->expectException(GitHubUserApiFailed::class);
        $api->user('at');
    }

    public function test_the_revoke_deletes_the_grant_with_basic_auth_and_the_token_in_the_body(): void
    {
        $api = $this->api(apiResponses: [new MockResponse('', ['http_code' => 204])]);

        $api->revokeGrant('at');

        $request = $this->apiRequests[0];
        self::assertSame('DELETE', $request['method']);
        self::assertSame('https://api.github.com/applications/the-client-id/grant', $request['url']);
        self::assertContains('Authorization: Basic '.base64_encode('the-client-id:the-client-secret'), $request['options']['headers']);
        self::assertSame(['access_token' => 'at'], json_decode((string) $request['options']['body'], true));
    }

    public function test_a_failed_revoke_throws_without_the_token(): void
    {
        $api = $this->api(apiResponses: [new MockResponse('{"message":"Not Found"}', ['http_code' => 404])]);

        try {
            $api->revokeGrant(self::SECRET_TOKEN);
            self::fail('A refused revoke must throw.');
        } catch (GitHubUserApiFailed $e) {
            self::assertSame('http_status', $e->reason);
            self::assertSame(404, $e->status);
            self::assertStringNotContainsString(self::SECRET_TOKEN, $e->getMessage());
        }
    }

    public function test_a_revoke_without_the_app_credentials_does_not_call_github(): void
    {
        $api = new GitHubUserApi(new MockHttpClient(), new MockHttpClient(), new GitHubAppConfiguration(null, null, null, null, null, null), new MockClock(self::NOW));

        try {
            $api->revokeGrant('at');
            self::fail('A revoke without credentials must throw.');
        } catch (GitHubUserApiFailed $e) {
            self::assertSame('not_configured', $e->reason);
        }
    }

    /**
     * @param list<MockResponse> $oauthResponses
     * @param list<MockResponse> $apiResponses
     */
    private function api(array $oauthResponses = [], array $apiResponses = []): GitHubUserApi
    {
        $record = (fn (array &$into): \Closure => static function (string $method, string $url, array $options) use (&$into): void {
            $into[] = ['method' => $method, 'url' => $url, 'options' => $options];
        });
        $oauth = $record($this->oauthRequests);
        $api = $record($this->apiRequests);

        return new GitHubUserApi(
            new MockHttpClient(static function (string $method, string $url, array $options) use ($oauth, &$oauthResponses): MockResponse {
                $oauth($method, $url, $options);

                return array_shift($oauthResponses) ?? new MockResponse('{}', ['http_code' => 500]);
            }, 'https://github.com'),
            new MockHttpClient(static function (string $method, string $url, array $options) use ($api, &$apiResponses): MockResponse {
                $api($method, $url, $options);

                return array_shift($apiResponses) ?? new MockResponse('{}', ['http_code' => 500]);
            }, 'https://api.github.com'),
            new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook', null, null),
            new MockClock(self::NOW),
        );
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}
