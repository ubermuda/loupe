<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Diagnostics;

use App\Module\GitHub\Diagnostics\GitHubAppApiCheck;
use App\Module\GitHub\Service\GitHubAppApi;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Ubermuda\HealthCheckBundle\Diagnostic;
use Ubermuda\HealthCheckBundle\DiagnosticState;

final class GitHubAppApiCheckTest extends TestCase
{
    private const array ALL_READ = ['checks' => 'read', 'contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read', 'statuses' => 'read'];

    private static string $privateKey;

    private int $requests = 0;

    public static function setUpBeforeClass(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $pem));
        self::assertIsString($pem);
        self::$privateKey = $pem;
    }

    public function test_no_api_variable_leaves_the_access_off_without_a_failure(): void
    {
        $diagnostic = $this->check([], appId: '', key: '');

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.app_api.not_offered', $diagnostic->detail);
        self::assertSame(0, $this->requests);
    }

    public function test_one_api_variable_alone_fails_and_names_the_other(): void
    {
        $diagnostic = $this->check([], key: '');

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('github.system_status.app_api.incomplete', $diagnostic->detail);
        self::assertSame(['%variables%' => 'GITHUB_APP_PRIVATE_KEY'], $diagnostic->detailParameters);
        self::assertSame(0, $this->requests);
    }

    public function test_every_installation_with_the_permissions_works(): void
    {
        $diagnostic = $this->check([
            ['id' => 1, 'account' => ['login' => 'ubermuda'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write'] + self::ALL_READ + ['members' => 'read']],
            ['id' => 2, 'account' => ['login' => 'acme'], 'permissions' => ['checks' => 'write', 'contents' => 'write', 'pull_requests' => 'write'] + self::ALL_READ],
        ]);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.app_api.working', $diagnostic->detail);
        self::assertSame(['%count%' => 2], $diagnostic->detailParameters);
    }

    public function test_an_installation_that_reads_pull_requests_without_write_warns_and_names_the_account(): void
    {
        $diagnostic = $this->check([
            ['id' => 1, 'account' => ['login' => 'ubermuda'], 'permissions' => self::ALL_READ],
            ['id' => 2, 'account' => ['login' => 'acme'], 'permissions' => ['pull_requests' => 'write'] + self::ALL_READ],
            ['id' => 3, 'account' => ['login' => 'other'], 'permissions' => self::ALL_READ],
        ]);

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('github.system_status.app_api.missing_comment_permission', $diagnostic->detail);
        self::assertSame(['%accounts%' => 'ubermuda, other'], $diagnostic->detailParameters);
    }

    public function test_an_installation_that_reads_contents_without_write_warns_and_names_the_account(): void
    {
        $diagnostic = $this->check([
            ['id' => 1, 'account' => ['login' => 'ubermuda'], 'permissions' => ['pull_requests' => 'write'] + self::ALL_READ],
            ['id' => 2, 'account' => ['login' => 'acme'], 'permissions' => ['contents' => 'write', 'pull_requests' => 'write'] + self::ALL_READ],
            ['id' => 3, 'account' => ['login' => 'other'], 'permissions' => ['pull_requests' => 'write'] + self::ALL_READ],
        ]);

        self::assertSame(DiagnosticState::Warning, $diagnostic->state);
        self::assertSame('github.system_status.app_api.missing_contents_write', $diagnostic->detail);
        self::assertSame(['%accounts%' => 'ubermuda, other'], $diagnostic->detailParameters);
    }

    public function test_no_installation_is_not_a_failure(): void
    {
        $diagnostic = $this->check([]);

        self::assertSame(DiagnosticState::Ok, $diagnostic->state);
        self::assertSame('github.system_status.app_api.none_installed', $diagnostic->detail);
        self::assertSame(1, $this->requests);
    }

    public function test_an_installation_that_misses_a_permission_fails_and_names_it(): void
    {
        $diagnostic = $this->check([
            ['id' => 1, 'account' => ['login' => 'acme'], 'permissions' => self::ALL_READ],
            ['id' => 2, 'account' => ['login' => 'ubermuda'], 'permissions' => ['checks' => 'read', 'metadata' => 'read', 'pull_requests' => 'read', 'statuses' => 'none']],
            ['id' => 3, 'account' => ['login' => 'other'], 'permissions' => ['metadata' => 'read']],
        ]);

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('github.system_status.app_api.missing_permissions', $diagnostic->detail);
        self::assertSame(
            ['%installations%' => 'ubermuda (contents, statuses); other (checks, contents, pull_requests, statuses)'],
            $diagnostic->detailParameters,
        );
    }

    public function test_a_refused_key_fails_with_the_reason_and_the_status_alone(): void
    {
        $diagnostic = $this->check(new MockResponse('{"message":"A JSON web token could not be decoded"}', ['http_code' => 401]));

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame('github.system_status.app_api.unreachable', $diagnostic->detail);
        self::assertSame(['%reason%' => 'http_status 401'], $diagnostic->detailParameters);
    }

    public function test_a_key_that_does_not_parse_fails_without_a_request(): void
    {
        $diagnostic = $this->check([], key: 'not a key');

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame(['%reason%' => 'bad_key'], $diagnostic->detailParameters);
        self::assertSame(0, $this->requests);
    }

    public function test_a_transport_error_fails_instead_of_throwing(): void
    {
        $diagnostic = $this->check(new MockResponse('', ['error' => 'Could not resolve host']));

        self::assertSame(DiagnosticState::Failed, $diagnostic->state);
        self::assertSame(['%reason%' => 'transport'], $diagnostic->detailParameters);
    }

    /**
     * @param list<array<string, mixed>>|MockResponse $answer the installations GitHub lists, or its whole answer
     * @param ?string                                 $key    null stands for a valid key
     */
    private function check(array|MockResponse $answer, string $appId = '123456', ?string $key = null): Diagnostic
    {
        $client = new MockHttpClient(function () use ($answer): MockResponse {
            ++$this->requests;

            return $answer instanceof MockResponse ? $answer : new MockResponse(json_encode($answer, \JSON_THROW_ON_ERROR));
        }, 'https://api.github.com');
        $configuration = new GitHubAppConfiguration(null, null, null, null, $appId, $key ?? self::$privateKey);

        return new GitHubAppApiCheck($configuration, new GitHubAppApi($client, $configuration, new MockClock()))();
    }
}
