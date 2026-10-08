<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

final class GitHubUserConnectionFlowTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    private const string TOKEN = 'ghu_user_token_never_shown';
    private const string ORIGIN = 'https://site.example';

    private KernelBrowser $client;

    /** @var list<array<int|string, string>> */
    private array $tokenRequests = [];

    /** @var list<MockResponse> */
    private array $tokenResponses = [];

    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $apiRequests = [];

    private MockResponse $userResponse;

    private MockResponse $revokeResponse;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->tokenResponses = [$this->tokens(self::TOKEN, 'ghr_refresh')];
        $this->userResponse = $this->json(['id' => 583231, 'login' => 'octocat']);
        $this->revokeResponse = new MockResponse('', ['http_code' => 204]);
        $container = static::getContainer();
        $container->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe-test', 'the-client-id', 'the-client-secret', 'hook', null, null));
        $container->set('github.oauth_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            parse_str(\is_string($options['body'] ?? null) ? $options['body'] : '', $body);
            $this->tokenRequests[] = array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $body);

            return array_shift($this->tokenResponses) ?? new MockResponse('{}', ['http_code' => 500]);
        }, 'https://github.com'));
        $container->set('github.api_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->apiRequests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => array_values(array_map(\strval(...), \is_array($options['headers'] ?? null) ? $options['headers'] : [])),
                'body' => \is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            return match (true) {
                'GET' === $method && str_ends_with($url, '/user') => $this->userResponse,
                'DELETE' === $method => $this->revokeResponse,
                default => new MockResponse('{}', ['http_code' => 404]),
            };
        }, 'https://api.github.com'));
    }

    public function test_connect_needs_a_signed_in_user(): void
    {
        $this->client->request(Request::METHOD_GET, '/account/github/connect');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function test_connect_sends_the_user_to_github_with_a_state_and_a_pkce_challenge(): void
    {
        $this->client->loginUser($this->signedUpUser('start'));

        $authorize = $this->startConnect();

        self::assertSame('the-client-id', $authorize['client_id']);
        self::assertSame('http://localhost/account/github/callback', $authorize['redirect_uri']);
        self::assertSame('S256', $authorize['code_challenge_method']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $authorize['code_challenge']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $authorize['state']);
    }

    public function test_without_the_app_connect_explains_and_goes_to_connected_apps(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null, null, null));
        $this->client->loginUser($this->signedUpUser('noapp'));

        $this->client->request(Request::METHOD_GET, '/account/github/connect');

        self::assertResponseRedirects('/account/connected-apps');
        self::assertStringContainsString('The operator has not registered a GitHub App', $this->followedText());
    }

    public function test_the_callback_stores_the_connection_for_the_signed_in_user(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $user = $this->signedUpUser('store');
        $this->client->loginUser($user);

        $challenge = $this->completeConnect();

        self::assertResponseIsSuccessful();
        self::assertSame('the-client-id', $this->tokenRequests[0]['client_id']);
        self::assertSame('the-code', $this->tokenRequests[0]['code']);
        self::assertSame('http://localhost/account/github/callback', $this->tokenRequests[0]['redirect_uri']);
        self::assertSame($challenge, rtrim(strtr(base64_encode(hash('sha256', $this->tokenRequests[0]['code_verifier'], true)), '+/', '-_'), '='));
        $connection = $this->connectionOf($user);
        self::assertNotNull($connection);
        self::assertSame('octocat', $connection->login);
        self::assertSame(583231, $connection->githubUserId);
        self::assertSame(self::TOKEN, $connection->accessToken);
        self::assertSame('ghr_refresh', $connection->refreshToken);
        self::assertNull($connection->expiredAt);
        self::assertGreaterThan(new \DateTimeImmutable('+7 hours'), $connection->accessTokenExpiresAt);
        self::assertSame(['Authorization: Bearer '.self::TOKEN], array_values(array_filter($this->apiRequests[0]['headers'], static fn (string $h): bool => str_starts_with($h, 'Authorization'))));
        self::assertSame(583231, $audit->record('github.user_connected')->context['githubUserId']);
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());
    }

    public function test_a_page_with_no_project_context_posts_to_nobody(): void
    {
        $this->client->loginUser($this->signedUpUser('noorigin'));

        $crawler = $this->completeConnectPage();

        self::assertSame('', $crawler->filter('[data-testid="github-connect-callback"]')->attr('data-target-origin'));
        self::assertStringContainsString('octocat', $crawler->filter('[data-callback-text]')->text());
        self::assertStringContainsString('/account/connected-apps', (string) $crawler->filter('[data-testid="github-connect-callback"]')->attr('data-connected-apps-url'));
    }

    public function test_the_page_posts_to_an_origin_the_project_allows(): void
    {
        $owner = $this->signedUpUser('allowed');
        $project = $this->projectOf($owner);
        $project->allowedOrigins = [self::ORIGIN];
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($owner);

        $crawler = $this->completeConnectPage(['project' => (string) $project->id, 'origin' => self::ORIGIN]);

        $node = $crawler->filter('[data-testid="github-connect-callback"]');
        self::assertSame(self::ORIGIN, $node->attr('data-target-origin'));
        self::assertSame(['type' => 'loupe-github-connect', 'connected' => true], json_decode((string) $node->attr('data-message'), true));
    }

    public function test_the_page_posts_to_nobody_for_an_origin_the_project_does_not_allow(): void
    {
        $owner = $this->signedUpUser('denied');
        $project = $this->projectOf($owner);
        $project->allowedOrigins = [self::ORIGIN];
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($owner);

        $crawler = $this->completeConnectPage(['project' => (string) $project->id, 'origin' => 'https://evil.example']);

        self::assertSame('', $crawler->filter('[data-testid="github-connect-callback"]')->attr('data-target-origin'));
        self::assertNotNull($this->connectionOf($owner));
    }

    public function test_an_origin_with_an_unknown_project_posts_to_nobody(): void
    {
        $this->client->loginUser($this->signedUpUser('noproject'));

        $crawler = $this->completeConnectPage(['project' => 'not-a-project', 'origin' => self::ORIGIN]);

        self::assertSame('', $crawler->filter('[data-testid="github-connect-callback"]')->attr('data-target-origin'));
    }

    public function test_a_second_connect_replaces_the_row_and_clears_the_expiry(): void
    {
        $user = $this->signedUpUser('again');
        $this->client->loginUser($user);
        $this->completeConnect();
        $first = $this->connectionOf($user);
        self::assertNotNull($first);
        $this->em()->getConnection()->executeStatement('UPDATE github_user_connections SET expired_at = now(), login = :login WHERE id = :id', ['login' => 'stale', 'id' => (string) $first->id]);
        $this->tokenResponses = [$this->tokens('second-access', 'second-refresh')];

        $this->completeConnect();

        $second = $this->connectionOf($user);
        self::assertNotNull($second);
        self::assertTrue($first->id?->equals($second->id));
        self::assertSame('octocat', $second->login);
        self::assertSame('second-access', $second->accessToken);
        self::assertNull($second->expiredAt);
    }

    public function test_a_state_that_does_not_match_stores_nothing(): void
    {
        $user = $this->signedUpUser('state');
        $this->client->loginUser($user);
        $this->startConnect();

        $this->client->request(Request::METHOD_GET, '/account/github/callback?code=the-code&state=forged');

        self::assertResponseRedirects('/account/connected-apps');
        self::assertStringContainsString('unexpected answer', $this->followedText());
        self::assertSame([], $this->tokenRequests);
        self::assertNull($this->connectionOf($user));
    }

    public function test_a_callback_with_no_pending_connection_is_refused(): void
    {
        $user = $this->signedUpUser('nopending');
        $this->client->loginUser($user);

        $this->client->request(Request::METHOD_GET, '/account/github/callback?code=the-code&state=x');

        self::assertResponseRedirects('/account/connected-apps');
        self::assertStringContainsString('Start again', $this->followedText());
        self::assertSame([], $this->tokenRequests);
    }

    public function test_the_callback_runs_once(): void
    {
        $this->client->loginUser($this->signedUpUser('once'));
        $authorize = $this->startConnect();
        $this->client->request(Request::METHOD_GET, '/account/github/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));
        $this->client->request(Request::METHOD_GET, '/account/github/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/account/connected-apps');
        self::assertCount(1, $this->tokenRequests);
    }

    public function test_a_person_who_cancels_on_github_stores_nothing(): void
    {
        $user = $this->signedUpUser('cancel');
        $this->client->loginUser($user);
        $authorize = $this->startConnect();

        $this->client->request(Request::METHOD_GET, '/account/github/callback?'.http_build_query(['error' => 'access_denied', 'state' => $authorize['state']]));

        self::assertResponseRedirects('/account/connected-apps');
        self::assertStringContainsString('You did not allow Loupe on GitHub', $this->followedText());
        self::assertNull($this->connectionOf($user));
    }

    public function test_an_app_that_does_not_expire_user_tokens_is_refused(): void
    {
        $this->tokenResponses = [$this->json(['access_token' => self::TOKEN, 'token_type' => 'bearer'])];
        $user = $this->signedUpUser('noexpiry');
        $this->client->loginUser($user);

        $this->completeConnect();

        self::assertResponseRedirects('/account/connected-apps');
        self::assertStringContainsString('turn on user token expiry', $this->followedText());
        self::assertNull($this->connectionOf($user));
    }

    public function test_a_github_failure_stores_nothing_and_shows_no_token(): void
    {
        $this->userResponse = new MockResponse('{}', ['http_code' => 503]);
        $user = $this->signedUpUser('down');
        $this->client->loginUser($user);

        $this->completeConnect();

        self::assertResponseRedirects('/account/connected-apps');
        $text = $this->followedText();
        self::assertStringContainsString('GitHub did not answer', $text);
        self::assertStringNotContainsString(self::TOKEN, $text);
        self::assertNull($this->connectionOf($user));
    }

    public function test_disconnect_revokes_the_grant_and_deletes_the_row(): void
    {
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $user = $this->signedUpUser('disconnect');
        $this->client->loginUser($user);
        $this->completeConnect();
        $this->apiRequests = [];

        $this->disconnect();

        self::assertResponseRedirects('/account/connected-apps');
        self::assertNull($this->connectionOf($user));
        self::assertCount(1, $this->apiRequests);
        self::assertSame('DELETE', $this->apiRequests[0]['method']);
        self::assertSame('https://api.github.com/applications/the-client-id/grant', $this->apiRequests[0]['url']);
        self::assertSame(['access_token' => self::TOKEN], json_decode($this->apiRequests[0]['body'], true));
        self::assertStringContainsString('Your GitHub account is disconnected.', $this->followedText());
        self::assertTrue($audit->record('github.user_disconnected')->context['revoked']);
    }

    public function test_disconnect_deletes_the_row_even_when_the_revoke_fails(): void
    {
        $user = $this->signedUpUser('revokefails');
        $this->client->loginUser($user);
        $this->completeConnect();
        $this->revokeResponse = new MockResponse('{}', ['http_code' => 500]);

        $this->disconnect();

        self::assertResponseRedirects('/account/connected-apps');
        self::assertNull($this->connectionOf($user));
        self::assertStringContainsString('GitHub did not confirm the removal', $this->followedText());
    }

    public function test_disconnect_refreshes_an_ended_access_token_before_it_revokes(): void
    {
        $user = $this->signedUpUser('refreshfirst');
        $this->client->loginUser($user);
        $this->completeConnect();
        $this->em()->getConnection()->executeStatement('UPDATE github_user_connections SET access_token_expires_at = now() - interval \'1 hour\'');
        $this->tokenResponses = [$this->tokens('renewed-access', 'renewed-refresh')];
        $this->apiRequests = [];

        $this->disconnect();

        self::assertSame('refresh_token', $this->tokenRequests[1]['grant_type']);
        self::assertSame(['access_token' => 'renewed-access'], json_decode($this->apiRequests[0]['body'], true));
        self::assertNull($this->connectionOf($user));
    }

    public function test_disconnect_of_an_expired_connection_makes_no_call_and_deletes_the_row(): void
    {
        $user = $this->signedUpUser('expired');
        $this->client->loginUser($user);
        $this->completeConnect();
        $this->em()->getConnection()->executeStatement('UPDATE github_user_connections SET expired_at = now()');
        $this->apiRequests = [];

        $this->disconnect();

        self::assertSame([], $this->apiRequests);
        self::assertNull($this->connectionOf($user));
    }

    public function test_disconnect_needs_a_signed_in_user(): void
    {
        $this->client->request(Request::METHOD_POST, '/account/github/disconnect', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function test_disconnect_refuses_a_request_without_a_csrf_token(): void
    {
        $user = $this->signedUpUser('nocsrf');
        $this->client->loginUser($user);
        $this->completeConnect();

        $this->client->request(Request::METHOD_POST, '/account/github/disconnect');

        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->connectionOf($user));
        self::assertCount(1, array_filter($this->apiRequests, static fn (array $r): bool => 'DELETE' === $r['method']) ?: [0]);
    }

    public function test_disconnect_does_not_answer_a_get(): void
    {
        $this->client->loginUser($this->signedUpUser('get'));

        $this->client->request(Request::METHOD_GET, '/account/github/disconnect');

        self::assertResponseStatusCodeSame(405);
    }

    public function test_the_connected_apps_page_offers_to_connect(): void
    {
        $this->client->loginUser($this->signedUpUser('page'));

        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertSelectorTextContains('[data-testid="github-account-section"]', 'GitHub account');
        self::assertSame('/account/github/connect', $crawler->filter('[data-testid="github-account-connect"]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-testid="github-account-row"]'));
    }

    public function test_the_connected_apps_page_shows_the_login_and_a_disconnect_form(): void
    {
        $this->client->loginUser($this->signedUpUser('shown'));
        $this->completeConnect();

        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertSame('octocat', $crawler->filter('[data-testid="github-account-login"]')->text());
        self::assertStringContainsString('Connected on', $crawler->filter('[data-testid="github-account-state"]')->text());
        self::assertCount(1, $crawler->filter('form[action="/account/github/disconnect"]'));
        self::assertCount(0, $crawler->filter('[data-testid="github-account-connect"]'));
        self::assertStringNotContainsString(self::TOKEN, (string) $this->client->getResponse()->getContent());
    }

    public function test_the_connected_apps_page_marks_an_expired_connection_and_offers_a_reconnect(): void
    {
        $this->client->loginUser($this->signedUpUser('expiredpage'));
        $this->completeConnect();
        $this->em()->getConnection()->executeStatement('UPDATE github_user_connections SET expired_at = now()');

        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertStringContainsString('The connection expired', $crawler->filter('[data-testid="github-account-state"]')->text());
        self::assertStringContainsString('Connect again', $crawler->filter('[data-testid="github-account-row"]')->text());
    }

    public function test_without_the_app_and_without_a_connection_the_section_is_absent(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null, null, null));
        $this->client->loginUser($this->signedUpUser('absent'));

        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertCount(0, $crawler->filter('[data-testid="github-account-section"]'));
    }

    public function test_without_the_app_a_connected_user_can_still_disconnect(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration(null, null, null, null, null, null));
        $user = $this->signedUpUser('gone');
        $this->em()->persist(new GitHubUserConnection($user, 7, 'octocat', 'a', 'r', new \DateTimeImmutable('+1 hour'), new \DateTimeImmutable('+1 day')));
        $this->em()->flush();
        $this->em()->clear();
        $this->client->loginUser($user);

        $crawler = $this->client->request(Request::METHOD_GET, '/account/connected-apps');

        self::assertCount(1, $crawler->filter('form[action="/account/github/disconnect"]'));
        self::assertCount(0, $crawler->filter('[data-testid="github-account-connect"]'));
    }

    private function disconnect(): void
    {
        $this->client->request(Request::METHOD_POST, '/account/github/disconnect', ['_csrf_token' => 'csrf-token'], [], ['HTTP_REFERER' => 'http://localhost/']);
    }

    /** @param array<string, string> $query */
    private function completeConnectPage(array $query = []): Crawler
    {
        $this->completeConnect($query);
        self::assertResponseIsSuccessful();

        return $this->client->getCrawler();
    }

    /**
     * @param array<string, string> $query
     *
     * @return string the PKCE challenge GitHub was sent
     */
    private function completeConnect(array $query = []): string
    {
        $authorize = $this->startConnect($query);
        $this->client->request(Request::METHOD_GET, '/account/github/callback?'.http_build_query(['code' => 'the-code', 'state' => $authorize['state']]));

        return $authorize['code_challenge'];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<int|string, string>
     */
    private function startConnect(array $query = []): array
    {
        $this->client->request(Request::METHOD_GET, '/account/github/connect'.([] === $query ? '' : '?'.http_build_query($query)));
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $authorize);

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : '', $authorize);
    }

    private function connectionOf(User $user): ?GitHubUserConnection
    {
        $this->em()->clear();
        $connections = static::getContainer()->get(GitHubUserConnectionRepository::class);
        self::assertInstanceOf(GitHubUserConnectionRepository::class, $connections);

        return $connections->findOneBy(['user' => $user->id]);
    }

    private function followedText(): string
    {
        return $this->client->followRedirect()->filter('body')->text();
    }

    private function tokens(string $access, string $refresh): MockResponse
    {
        return $this->json(['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 28800, 'refresh_token_expires_in' => 15897600, 'token_type' => 'bearer']);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
