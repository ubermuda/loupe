<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\ConfiguredTrustedClientIds;
use App\Module\OAuth\ClientMetadata\SystemHostResolver;
use App\Module\OAuth\ClientMetadata\TrustedClientIds;
use App\Module\Project\Entity\Project;
use App\Tests\Support\FakeHostResolver;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** A client whose client_id is the URL of its metadata document (CIMD). */
final class ClientMetadataDocumentFlowTest extends WebTestCase
{
    public const string CLIENT_ID = 'https://client.example/oauth/metadata';
    private const string REDIRECT_URI = 'http://localhost:53712/callback';
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $user;
    private Project $project;
    private int $fetches = 0;
    private ?string $icon = 'icon-bytes';
    private TrustedClientIds $trustedClientIds;

    /** @var array<string, mixed> */
    private array $document = [
        'client_id' => self::CLIENT_ID,
        'client_name' => 'Example Agent',
        'redirect_uris' => ['http://localhost/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
    ];

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        $container = static::getContainer();
        $container->set(SystemHostResolver::class, new FakeHostResolver(['client.example' => ['160.79.104.10']]));
        $container->set('oauth.client_metadata_client', new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_ends_with($url, '/favicon.ico')) {
                return null === $this->icon
                    ? new MockResponse('', ['http_code' => 404])
                    : new MockResponse($this->icon, ['response_headers' => ['content-type' => 'image/png']]);
            }

            ++$this->fetches;

            return new MockResponse((string) json_encode($this->document), ['response_headers' => ['content-type' => 'application/json']]);
        }));

        // One instance for the whole test: the container refuses a second set()
        // once a service is built, so each test changes its entries instead.
        $this->trustedClientIds = new class implements TrustedClientIds {
            /** @var list<string> */
            public array $entries = [ClientMetadataDocumentFlowTest::CLIENT_ID];

            #[\Override]
            public function isTrusted(ClientIdUrl $url): bool
            {
                return new ConfiguredTrustedClientIds($this->entries)->isTrusted($url);
            }
        };
        $container->set(ConfiguredTrustedClientIds::class, $this->trustedClientIds);

        $this->scenario = new OAuthScenario($container);
        $this->user = $this->scenario->createUser('riley@example.com');
        $this->project = $this->scenario->createProject($this->user, 'Riley site');
    }

    public function test_a_document_client_authorizes_exchanges_and_calls_mcp(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'client.example');
        self::assertSelectorTextContains('[data-testid="oauth-consent-client-host"]', 'client.example');
        self::assertSelectorTextContains('[data-testid="oauth-consent-client-name"]', 'Example Agent');
        self::assertSelectorExists('[data-testid="oauth-consent-loopback-warning"]');

        $tokens = $this->scenario->grantTokens($this->browser, $this->user, $this->project, $this->authorizeOverrides());
        self::assertSame(1, $this->fetches, 'the cached document serves the second authorize request');

        $this->browser->request(Request::METHOD_POST, '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']], content: self::INIT);
        self::assertResponseIsSuccessful();

        $identifier = ClientIdUrl::parse(self::CLIENT_ID)?->identifier;
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(['scopes' => 'mcp', 'redirect_uris' => 'http://localhost/callback'], $connection->fetchAssociative('SELECT scopes, redirect_uris FROM oauth2_client WHERE identifier = ?', [$identifier]));
        self::assertSame(self::CLIENT_ID, $connection->fetchOne('SELECT url FROM oauth_client_metadata_document WHERE client_identifier = ?', [$identifier]));
        self::assertSame($identifier, $connection->fetchOne('SELECT client FROM oauth2_access_token'));
    }

    public function test_the_consent_page_shows_the_icon_of_the_verified_host(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        $icon = $crawler->filter('.lp-consent__badge img');
        self::assertCount(1, $icon);
        self::assertSame('', $icon->attr('alt'), 'the icon claims no identity, so it is decorative');
        self::assertStringNotContainsString('cannot check', $crawler->filter('[data-testid="oauth-consent-client-name"]')->text(), 'a vouched client needs no warning');
        self::assertCount(0, $crawler->filter('.lp-consent__row-muted'), 'a vouched client shows its host alone');
        self::assertSame('16', $icon->attr('width'), 'a fixed box keeps the row from moving');

        $this->browser->request(Request::METHOD_GET, (string) $icon->attr('src'));
        self::assertResponseIsSuccessful();
        self::assertSame('icon-bytes', $this->browser->getResponse()->getContent());
        self::assertSame('image/png', $this->browser->getResponse()->headers->get('Content-Type'));
        self::assertSame('nosniff', $this->browser->getResponse()->headers->get('X-Content-Type-Options'));
    }

    public function test_a_host_without_an_icon_renders_the_page_as_before(): void
    {
        $this->icon = null;

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="oauth-consent-client-host"]', 'client.example');
        self::assertCount(0, $crawler->filter('.lp-consent__badge img'));

        $this->browser->request(Request::METHOD_GET, '/oauth/client-icon/'.(ClientIdUrl::parse(self::CLIENT_ID)->identifier ?? ''));
        self::assertResponseStatusCodeSame(404);
    }

    public function test_an_unvouched_client_shows_its_whole_client_id_and_no_icon(): void
    {
        $this->trustedClientIds->entries = [];

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        $host = $crawler->filter('[data-testid="oauth-consent-client-host"]');
        self::assertStringContainsString('client.example', $host->text());
        self::assertStringContainsString('/oauth/metadata', $host->text(), 'the path is what tells a lookalike apart');
        self::assertSame(self::CLIENT_ID, $host->attr('title'), 'the whole client id stays available');
        self::assertSame('client.example', $host->filter('.lp-consent__row-strong')->text(), 'the host carries the emphasis');
        self::assertSame('/oauth/metadata', $host->filter('.lp-consent__row-muted')->text());
        self::assertStringContainsString('cannot check', $crawler->filter('[data-testid="oauth-consent-client-name"]')->text());
        self::assertCount(0, $crawler->filter('.lp-consent__badge img'), 'an unvouched client borrows no host icon');
    }

    public function test_a_lookalike_path_does_not_borrow_the_trust_of_the_host_it_names(): void
    {
        $this->trustedClientIds->entries = ['https://claude.ai/oauth/claude-code-client-metadata'];

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertSame('client.example', $crawler->filter('.lp-consent__row-strong')->text());
        self::assertStringContainsString('cannot check', $crawler->filter('[data-testid="oauth-consent-client-name"]')->text());
        self::assertCount(0, $crawler->filter('.lp-consent__badge img'));
    }

    public function test_a_document_whose_client_id_differs_is_refused_without_a_redirect(): void
    {
        $this->document['client_id'] = 'https://evil.example/oauth/metadata';

        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
        self::assertSame('invalid_client', json_decode((string) $this->browser->getResponse()->getContent(), true)['error'] ?? null);
        // Scoped to this client: the device flow seeds a client row of its own.
        $rows = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM oauth2_client WHERE identifier = ?',
            [ClientIdUrl::parse(self::CLIENT_ID)->identifier ?? ''],
        );
        self::assertSame(0, (int) $rows);
    }

    public function test_the_token_endpoint_never_fetches_an_unknown_document(): void
    {
        $response = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'code' => 'x',
            'code_verifier' => $this->scenario->codeVerifier,
        ]);

        self::assertSame('invalid_client', $response['error'] ?? null);
        self::assertSame(0, $this->fetches);
    }

    public function test_the_hashed_identifier_is_not_a_client_id(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        self::assertResponseIsSuccessful();

        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', [
            'client_id' => ClientIdUrl::parse(self::CLIENT_ID)->identifier ?? '',
            'redirect_uri' => self::REDIRECT_URI,
        ]));

        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
    }

    public function test_a_document_client_gets_no_other_scope(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('agent', $this->authorizeOverrides()));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_scope', $query['error'] ?? null);
    }

    public function test_a_refetch_keeps_an_operator_deactivation(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $identifier = ClientIdUrl::parse(self::CLIENT_ID)->identifier ?? '';
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('UPDATE oauth2_client SET active = false WHERE identifier = ?', [$identifier]);
        $connection->executeStatement("UPDATE oauth_client_metadata_document SET expires_at = NOW() - INTERVAL '1 minute'");
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertSame(2, $this->fetches);
        self::assertFalse((bool) $connection->fetchOne('SELECT active FROM oauth2_client WHERE identifier = ?', [$identifier]));
    }

    public function test_a_fresh_document_without_its_client_row_is_fetched_again(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM oauth2_client');
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->fetches);
    }

    public function test_fetches_are_rate_limited_per_user(): void
    {
        static::getContainer()->set('limiter.oauth_client_metadata_fetch', new RateLimiterFactory(
            ['id' => 'oauth_client_metadata_fetch', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'],
            new InMemoryStorage(),
        ));
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        self::assertResponseIsSuccessful();

        $other = 'https://client.example/oauth/other';
        $this->document['client_id'] = $other;
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', ['client_id' => $other, 'redirect_uri' => self::REDIRECT_URI]));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('temporarily_unavailable', json_decode((string) $this->browser->getResponse()->getContent(), true)['error'] ?? null);
        self::assertSame(1, $this->fetches);
    }

    private function authorizeUrl(): string
    {
        return $this->scenario->authorizeUrl('mcp', $this->authorizeOverrides());
    }

    /** @return array<string, string> */
    private function authorizeOverrides(): array
    {
        return ['client_id' => self::CLIENT_ID, 'redirect_uri' => self::REDIRECT_URI];
    }
}
