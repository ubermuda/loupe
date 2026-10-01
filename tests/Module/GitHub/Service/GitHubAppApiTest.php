<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Service;

use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppApiFailed;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\GitHubAppInstallationAccess;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GitHubAppApiTest extends TestCase
{
    private const string NOW = '2026-09-27 12:00:00';
    private const string TOKEN = 'ghs_the-installation-token';

    private static string $privateKey;
    /** @var non-empty-string */
    private static string $publicKey;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        self::$privateKey = $pem;
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::$publicKey = $details['key'] ?: self::fail('No public key.');
    }

    /** @return iterable<string, array{string}> */
    public static function keyShapes(): iterable
    {
        yield 'a multi-line PEM' => ['multi-line'];
        yield 'a PEM with a literal \n for each line break' => ['escaped'];
    }

    #[DataProvider('keyShapes')]
    public function test_the_token_request_carries_an_rs256_jwt_signed_as_the_app(string $shape): void
    {
        $key = 'escaped' === $shape ? str_replace("\n", '\n', trim(self::$privateKey)) : self::$privateKey;
        $api = $this->api([$this->created(['token' => self::TOKEN])], key: $key);

        self::assertSame(self::TOKEN, $api->installationToken(42));

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://api.github.com/app/installations/42/access_tokens', $this->requests[0]['url']);

        $jwt = $this->bearer(0);
        [$header, $claims] = $this->decode($jwt);
        self::assertSame('RS256', $header['alg']);
        self::assertSame('123456', $claims['iss']);
        self::assertSame(new \DateTimeImmutable(self::NOW)->getTimestamp() - 60, $claims['iat']);
        self::assertSame(new \DateTimeImmutable(self::NOW)->getTimestamp() + 540, $claims['exp']);

        $token = new Parser(new JoseEncoder())->parse($jwt);
        self::assertTrue(new Validator()->validate($token, new SignedWith(new Sha256(), InMemory::plainText(self::$publicKey))));
    }

    public function test_a_token_is_reused_for_fifty_minutes_then_fetched_again(): void
    {
        $clock = new MockClock(self::NOW);
        $api = $this->api([
            $this->created(['token' => 'ghs_first']),
            $this->created(['token' => 'ghs_other_installation']),
            $this->created(['token' => 'ghs_second']),
        ], clock: $clock);

        self::assertSame('ghs_first', $api->installationToken(42));
        self::assertCount(1, $this->requests);

        $clock->modify('+49 minutes');
        self::assertSame('ghs_first', $api->installationToken(42));
        self::assertCount(1, $this->requests);

        self::assertSame('ghs_other_installation', $api->installationToken(7));
        self::assertCount(2, $this->requests);

        $clock->modify('+1 minute');
        self::assertSame('ghs_second', $api->installationToken(42));
        self::assertCount(3, $this->requests);
    }

    public function test_without_the_app_id_or_key_nothing_is_sent(): void
    {
        $api = $this->api([], appId: '');

        $failure = $this->failure(static fn () => $api->installationToken(42));

        self::assertSame('not_configured', $failure->reason);
        self::assertSame([], $this->requests);
    }

    public function test_a_key_that_does_not_parse_fails_before_any_request(): void
    {
        $api = $this->api([], key: "-----BEGIN RSA PRIVATE KEY-----\nnot-a-key\n-----END RSA PRIVATE KEY-----");

        $failure = $this->failure(static fn () => $api->installations());

        self::assertSame('bad_key', $failure->reason);
        self::assertNull($failure->getPrevious());
        self::assertSame([], $this->requests);
    }

    public function test_a_refused_request_names_the_status_and_no_secret(): void
    {
        $api = $this->api([new MockResponse('{"message":"A JSON web token could not be decoded","token":"ghs_leak"}', ['http_code' => 401])]);

        $failure = $this->failure(static fn () => $api->installationToken(42));

        self::assertSame('http_status', $failure->reason);
        self::assertSame(401, $failure->status);
        self::assertNull($failure->getPrevious());
        self::assertStringNotContainsString($this->bearer(0), $failure->getMessage());
        self::assertStringNotContainsString('ghs_leak', $failure->getMessage());
        self::assertStringNotContainsString('PRIVATE KEY', $failure->getMessage());
    }

    public function test_a_transport_error_fails_as_transport(): void
    {
        $api = $this->api([new MockResponse('', ['error' => 'Could not resolve host'])]);

        self::assertSame('transport', $this->failure(static fn () => $api->installationToken(42))->reason);
    }

    public function test_a_body_without_a_token_or_not_json_is_malformed(): void
    {
        $api = $this->api([$this->created(['expires_at' => '2026-09-27T13:00:00Z']), new MockResponse('<html>', ['http_code' => 201])]);

        self::assertSame('malformed_body', $this->failure(static fn () => $api->installationToken(42))->reason);
        self::assertSame('malformed_body', $this->failure(static fn () => $api->installationToken(42))->reason);
    }

    public function test_installations_reads_every_page_with_the_app_jwt(): void
    {
        $firstPage = [];
        for ($id = 1; $id <= 100; ++$id) {
            $firstPage[] = ['id' => $id, 'account' => ['login' => 'org-'.$id], 'permissions' => ['metadata' => 'read']];
        }
        $api = $this->api([
            $this->ok($firstPage),
            $this->ok([
                ['id' => 101, 'account' => ['login' => 'ubermuda'], 'permissions' => ['checks' => 'write', 'contents' => 'read', 'members' => 'read']],
                ['id' => 102, 'account' => null, 'permissions' => []],
                ['account' => ['login' => 'no-id']],
            ]),
        ]);

        $installations = $api->installations();

        self::assertCount(102, $installations);
        self::assertEquals(
            new GitHubAppInstallationAccess(101, 'ubermuda', ['checks' => 'write', 'contents' => 'read', 'members' => 'read']),
            $installations[100],
        );
        self::assertSame('#102', $installations[101]->account);
        self::assertCount(2, $this->requests);
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertStringStartsWith('https://api.github.com/app/installations?', $this->requests[1]['url']);
        parse_str((string) parse_url($this->requests[1]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['per_page' => '100', 'page' => '2'], $query);
        self::assertSame('123456', $this->decode($this->bearer(1))[1]['iss']);
    }

    public function test_installations_refuses_a_body_that_is_not_a_list(): void
    {
        $api = $this->api([$this->ok(['installations' => []])]);

        self::assertSame('malformed_body', $this->failure(static fn () => $api->installations())->reason);
    }

    public function test_a_write_grant_counts_as_read_access(): void
    {
        $access = new GitHubAppInstallationAccess(1, 'ubermuda', ['checks' => 'write', 'contents' => 'read', 'statuses' => 'none']);

        self::assertSame(['statuses', 'metadata'], $access->missingReadAccess(['checks', 'contents', 'statuses', 'metadata']));
    }

    public function test_only_a_write_grant_counts_as_write_access(): void
    {
        $access = new GitHubAppInstallationAccess(1, 'ubermuda', ['pull_requests' => 'read', 'checks' => 'write', 'statuses' => 'none']);

        self::assertSame(['pull_requests', 'statuses', 'metadata'], $access->missingWriteAccess(['pull_requests', 'checks', 'statuses', 'metadata']));
    }

    public function test_post_sends_a_json_body_with_the_installation_token_and_answers_the_created_resource(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->created(['id' => 99, 'body' => 'Hello']),
        ]);

        self::assertSame(['id' => 99, 'body' => 'Hello'], $api->post(42, '/repos/acme/widgets/issues/7/comments', ['body' => 'Hello']));
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/acme/widgets/issues/7/comments', $this->requests[1]['url']);
        self::assertSame(self::TOKEN, $this->bearer(1));
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['body' => 'Hello'], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_put_sends_a_json_body_with_the_installation_token_and_answers_an_accepted_body(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('{"message":"Updating pull request branch."}', ['http_code' => 202]),
        ]);

        self::assertSame(['message' => 'Updating pull request branch.'], $api->put(42, '/repos/acme/widgets/pulls/7/update-branch', ['expected_head_sha' => 'abc']));
        self::assertSame('PUT', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/acme/widgets/pulls/7/update-branch', $this->requests[1]['url']);
        self::assertSame(self::TOKEN, $this->bearer(1));
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['expected_head_sha' => 'abc'], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_put_answers_an_empty_array_for_an_accepted_empty_body(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('', ['http_code' => 202]),
        ]);

        self::assertSame([], $api->put(42, '/repos/acme/widgets/pulls/7/update-branch', ['expected_head_sha' => 'abc']));
    }

    public function test_put_names_the_status_of_a_refused_request(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('{"message":"expected head sha didn\'t match current head ref."}', ['http_code' => 422]),
        ]);

        $failure = $this->failure(static fn () => $api->put(42, '/repos/acme/widgets/pulls/7/update-branch', ['expected_head_sha' => 'abc']));

        self::assertSame('http_status', $failure->reason);
        self::assertSame(422, $failure->status);
    }

    public function test_post_names_the_status_of_a_refused_request(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('{"message":"Resource not accessible by integration"}', ['http_code' => 403]),
        ]);

        $failure = $this->failure(static fn () => $api->post(42, '/repos/acme/widgets/issues/7/comments', ['body' => 'Hello']));

        self::assertSame('http_status', $failure->reason);
        self::assertSame(403, $failure->status);
    }

    /** @return iterable<string, array{int, array<string, string>, bool}> */
    public static function rateLimits(): iterable
    {
        yield 'a 403 with no rate limit headers' => [403, [], false];
        yield 'a 403 with no requests left' => [403, ['x-ratelimit-remaining' => '0'], true];
        yield 'a 403 with requests left' => [403, ['x-ratelimit-remaining' => '12'], false];
        yield 'a 403 that asks to retry later' => [403, ['retry-after' => '60'], true];
        yield 'a 429' => [429, [], true];
        yield 'a 404 with no requests left' => [404, ['x-ratelimit-remaining' => '0'], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rateLimits')]
    public function test_a_refused_request_says_whether_github_limited_the_rate(int $status, array $headers, bool $rateLimited): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('{"message":"API rate limit exceeded"}', ['http_code' => $status, 'response_headers' => $headers]),
        ]);

        $failure = $this->failure(static fn () => $api->post(42, '/repos/acme/widgets/issues/7/comments', ['body' => 'Hello']));

        self::assertSame($status, $failure->status);
        self::assertSame($rateLimited, $failure->rateLimited);
    }

    public function test_graphql_posts_the_query_with_the_installation_token_and_answers_the_data(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok(['data' => ['repository' => ['pullRequest' => null]], 'errors' => [['type' => 'NOT_FOUND']]]),
        ]);

        $data = $api->graphql(42, 'query($n:Int!){x}', ['n' => 7]);

        self::assertSame(['repository' => ['pullRequest' => null]], $data);
        self::assertCount(2, $this->requests);
        self::assertSame('POST', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/graphql', $this->requests[1]['url']);
        self::assertSame(self::TOKEN, $this->bearer(1));
        $body = $this->requests[1]['options']['body'] ?? null;
        self::assertIsString($body);
        self::assertSame(['query' => 'query($n:Int!){x}', 'variables' => ['n' => 7]], json_decode($body, true, flags: \JSON_THROW_ON_ERROR));
    }

    public function test_graphql_without_data_fails_as_graphql_error(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok(['errors' => [['message' => 'Parse error']]]),
        ]);

        self::assertSame('graphql_error', $this->failure(static fn () => $api->graphql(42, '{', []))->reason);
    }

    public function test_graphql_with_data_and_an_error_other_than_not_found_fails_as_graphql_error(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok(['data' => ['repository' => ['pullRequest' => null]], 'errors' => [['type' => 'NOT_FOUND'], ['type' => 'SERVICE_UNAVAILABLE']]]),
        ]);

        self::assertSame('graphql_error', $this->failure(static fn () => $api->graphql(42, '{x}', []))->reason);
    }

    public function test_a_graphql_error_carries_the_type_of_the_error_that_failed_the_call(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok(['data' => ['closePullRequest' => null], 'errors' => [['type' => 'NOT_FOUND'], ['type' => 'FORBIDDEN', 'message' => 'Resource not accessible by integration']]]),
        ]);

        $failure = $this->failure(static fn () => $api->graphql(42, '{x}', []));

        self::assertSame('graphql_error', $failure->reason);
        self::assertSame('FORBIDDEN', $failure->graphqlType);
    }

    public function test_a_graphql_error_without_a_type_carries_none(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok(['errors' => [['message' => 'Parse error']]]),
        ]);

        self::assertNull($this->failure(static fn () => $api->graphql(42, '{', []))->graphqlType);
    }

    public function test_get_sends_the_query_with_the_installation_token(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            $this->ok([['type' => 'deletion']]),
        ]);

        self::assertSame([['type' => 'deletion']], $api->get(42, '/repos/acme/widgets/rules/branches/main', ['page' => 1]));
        self::assertSame('GET', $this->requests[1]['method']);
        self::assertSame('https://api.github.com/repos/acme/widgets/rules/branches/main?page=1', $this->requests[1]['url']);
        self::assertSame(self::TOKEN, $this->bearer(1));
    }

    public function test_get_names_the_status_of_a_refused_request(): void
    {
        $api = $this->api([
            $this->created(['token' => self::TOKEN]),
            new MockResponse('{"message":"Not Found"}', ['http_code' => 404]),
        ]);

        $failure = $this->failure(static fn () => $api->get(42, '/repos/acme/widgets/compare/main...abc'));

        self::assertSame('http_status', $failure->reason);
        self::assertSame(404, $failure->status);
    }

    /** @param list<MockResponse> $responses */
    private function api(array $responses, ?string $appId = '123456', ?string $key = null, ?MockClock $clock = null): GitHubAppApi
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? throw new \LogicException('Unexpected request '.$method.' '.$url);
        }, 'https://api.github.com');

        return new GitHubAppApi(
            $client,
            new GitHubAppConfiguration(null, null, null, null, $appId, $key ?? self::$privateKey),
            $clock ?? new MockClock(self::NOW),
        );
    }

    /** @param array<mixed> $body */
    private function created(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    /** @param array<mixed> $body */
    private function ok(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    /** @return non-empty-string */
    private function bearer(int $request): string
    {
        $headers = $this->requests[$request]['options']['normalized_headers'] ?? null;
        self::assertIsArray($headers);
        $authorization = $headers['authorization'][0] ?? null;
        self::assertIsString($authorization);
        self::assertStringStartsWith('Authorization: Bearer ', $authorization);

        return substr($authorization, \strlen('Authorization: Bearer ')) ?: self::fail('Empty bearer.');
    }

    /** @return array{array<string, mixed>, array<string, mixed>} the header and the claims, as sent */
    private function decode(string $jwt): array
    {
        [$header, $claims] = explode('.', $jwt);

        return [
            json_decode(base64_decode(strtr($header, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR),
            json_decode(base64_decode(strtr($claims, '-_', '+/')), true, flags: \JSON_THROW_ON_ERROR),
        ];
    }

    private function failure(\Closure $call): GitHubAppApiFailed
    {
        try {
            $call();
        } catch (GitHubAppApiFailed $failure) {
            return $failure;
        }

        self::fail('Expected GitHubAppApiFailed.');
    }
}
