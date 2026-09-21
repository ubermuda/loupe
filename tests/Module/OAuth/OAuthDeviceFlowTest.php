<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class OAuthDeviceFlowTest extends WebTestCase
{
    private const string CLIENT_ID = 'loupe-cli';
    private const string DEVICE_GRANT = 'urn:ietf:params:oauth:grant-type:device_code';

    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $user;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->user = $this->scenario->createUser('riley@example.com');
    }

    public function test_device_authorization_approve_poll_call_and_refresh(): void
    {
        $start = $this->startDeviceFlow();
        self::assertSame($this->issuer().'/oauth/device', $start['verification_uri']);
        self::assertSame($start['verification_uri'].'?user_code='.$start['user_code'], $start['verification_uri_complete']);
        self::assertMatchesRegularExpression('/\A[BCDFGHJKLMNPQRSTVWXZ]{8}\z/', $start['user_code']);
        self::assertSame(5, $start['interval']);
        self::assertGreaterThan(0, $start['expires_in']);

        self::assertSame('authorization_pending', $this->poll($start['device_code'])['error']);
        self::assertSame('slow_down', $this->poll($start['device_code'])['error'], 'a poll inside the interval slows the client down');

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="oauth-device-consent"]', 'Loupe CLI');
        self::assertSelectorTextContains('[data-testid="oauth-device-user-code"]', substr($start['user_code'], 0, 4).'-'.substr($start['user_code'], 4));

        $this->browser->submit($crawler->selectButton('device_consent_form_approve')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="oauth-device-approved"]');

        $this->browser->restart();
        $tokens = $this->poll($start['device_code']);
        self::assertResponseIsSuccessful();
        self::assertIsString($tokens['access_token'] ?? null);
        self::assertIsString($tokens['refresh_token'] ?? null);
        self::assertIsInt($tokens['expires_in'] ?? null);

        self::assertSame(200, $this->callAgentApi($tokens['access_token']));

        self::assertSame('invalid_request', $this->poll($start['device_code'])['error'] ?? null, 'a device code gives tokens once');

        $refreshed = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsString($refreshed['access_token'] ?? null);
        self::assertIsString($refreshed['refresh_token'] ?? null);
        self::assertSame(200, $this->callAgentApi($refreshed['access_token']));
        self::assertSame(401, $this->callAgentApi($tokens['access_token']), 'a refresh revokes the old access token');

        // The grace window accepts the rotated token once, so a CLI whose
        // refresh response was lost recovers. The second reuse is refused.
        $recovered = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertIsString($recovered['refresh_token']);
        self::assertNotSame($tokens['refresh_token'], $recovered['refresh_token']);

        $replay = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $replay['error']);
    }

    public function test_the_consent_answers_the_question_in_two_rows(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="oauth-device-consent"] .lp-consent__question', 'Loupe CLI');
        $rows = $crawler->filter('[data-testid="oauth-device-consent"] .lp-consent__row');
        self::assertCount(2, $rows);
        self::assertStringContainsString(substr($start['user_code'], 0, 4).'-'.substr($start['user_code'], 4), $rows->eq(0)->text());
        self::assertStringContainsString('It can list your projects', $rows->eq(1)->text(), 'the scope row reads as it does on the consent page');
    }

    /** The consent footnote sends the reader to Connected apps, so a device grant must arrive there. */
    public function test_an_approved_device_appears_under_connected_apps(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        $this->browser->submit($crawler->selectButton('device_consent_form_approve')->form());
        self::assertIsString($this->poll($start['device_code'])['access_token'] ?? null);

        $this->browser->request(Request::METHOD_GET, '/account/connected-apps');
        self::assertSelectorTextContains('[data-connected-app="'.self::CLIENT_ID.'"]', 'Loupe CLI');
    }

    public function test_the_entry_form_normalises_the_code_and_leads_to_the_consent(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, '/oauth/device');
        self::assertResponseIsSuccessful();
        $typed = strtolower(substr($start['user_code'], 0, 4)).' - '.strtolower(substr($start['user_code'], 4));
        $this->browser->submit($crawler->selectButton('device_code_entry_form_submit')->form([
            'device_code_entry_form[userCode]' => $typed,
        ]));

        self::assertResponseRedirects('/oauth/device?user_code='.$start['user_code']);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('[data-testid="oauth-device-consent"]', 'Loupe CLI');
    }

    /**
     * A session that has double-submitted once must double-submit on every later
     * stateless form. A click before the CSRF script loads sends none, so these
     * forms must not use a stateless token.
     */
    public function test_both_forms_work_before_the_csrf_script_has_loaded(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, '/oauth/device');
        $this->markSessionAsDoubleSubmitting();
        $this->browser->submit($crawler->selectButton('device_code_entry_form_submit')->form([
            'device_code_entry_form[userCode]' => $start['user_code'],
        ]));
        self::assertResponseRedirects('/oauth/device?user_code='.$start['user_code']);

        $crawler = $this->browser->followRedirect();
        $this->markSessionAsDoubleSubmitting();
        $this->browser->submit($crawler->selectButton('device_consent_form_approve')->form());
        self::assertSelectorExists('[data-testid="oauth-device-approved"]');
    }

    public function test_the_consent_refuses_a_forged_csrf_token(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        $form = $crawler->selectButton('device_consent_form_approve')->form();
        $values = $form->getPhpValues();
        $values['device_consent_form']['_token'] = str_repeat('a', 43);
        $this->browser->request(Request::METHOD_POST, $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('[data-testid="oauth-device-approved"]');
        $this->browser->restart();
        self::assertSame('authorization_pending', $this->poll($start['device_code'])['error']);
    }

    /** The Vitest suite drives the controller. This pins the hooks it needs to the page. */
    public function test_the_entry_page_carries_the_code_boxes_and_the_plain_field(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, '/oauth/device');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="device-code-input"][data-device-code-input-length-value="8"]');
        self::assertSelectorExists('[data-device-code-input-target="field"]');
        $group = $crawler->filter('[data-device-code-input-target="boxes"]');
        self::assertSame('group', $group->attr('role'));
        self::assertSame($crawler->filter('label.lp-label')->attr('id'), $group->attr('aria-labelledby'));
        self::assertNotEmpty($crawler->filter('[data-device-code-input-character-label-value]')->attr('data-device-code-input-character-label-value'));
    }

    public function test_deny_makes_the_poll_answer_access_denied(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        $this->browser->submit($crawler->selectButton('device_consent_form_deny')->form());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="oauth-device-denied"]');

        $this->browser->restart();
        self::assertSame('access_denied', $this->poll($start['device_code'])['error']);
        self::assertSame(0, $this->countRows('oauth2_access_token'));
    }

    public function test_an_approved_code_cannot_be_approved_again(): void
    {
        $start = $this->startDeviceFlow();

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        $this->browser->submit($crawler->selectButton('device_consent_form_deny')->form());

        $stranger = $this->scenario->createUser('stranger@example.com');
        $this->browser->loginUser($stranger);
        $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        self::assertSelectorNotExists('[data-testid="oauth-device-consent"]');
        self::assertSelectorTextContains('[data-testid="oauth-device-entry"]', 'not valid');
    }

    public function test_an_expired_code_is_refused_on_the_page_and_at_the_token_endpoint(): void
    {
        $start = $this->startDeviceFlow();
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE oauth2_device_code SET expiry = NOW() - INTERVAL '1 minute'",
        );

        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->verificationPath($start['verification_uri_complete']));
        self::assertSelectorNotExists('[data-testid="oauth-device-consent"]');
        self::assertSelectorTextContains('[data-testid="oauth-device-entry"]', 'not valid');

        $this->browser->restart();
        self::assertSame('expired_token', $this->poll($start['device_code'])['error']);
    }

    public function test_an_unknown_code_is_refused(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, '/oauth/device');
        $this->browser->submit($crawler->selectButton('device_code_entry_form_submit')->form([
            'device_code_entry_form[userCode]' => 'BBBB-BBBB',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-testid="oauth-device-entry"]', 'not valid');
    }

    public function test_the_verification_page_needs_a_signed_in_user(): void
    {
        $this->browser->request(Request::METHOD_GET, '/oauth/device?user_code=BBBBBBBB');

        self::assertResponseRedirects('/login');
    }

    public function test_the_cli_client_may_not_ask_for_a_project_scope(): void
    {
        $this->browser->request(Request::METHOD_POST, '/oauth/device-authorization', [
            'client_id' => self::CLIENT_ID,
            'scope' => 'mcp',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_scope', $this->json()['error'] ?? null);
        self::assertSame(0, $this->countRows('oauth2_device_code'));
    }

    public function test_a_client_that_does_not_list_the_device_grant_cannot_start_a_flow(): void
    {
        $this->scenario->createClient();

        $this->browser->request(Request::METHOD_POST, '/oauth/device-authorization', [
            'client_id' => OAuthScenario::CLIENT_ID,
            'scope' => 'agent',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('unauthorized_client', $this->json()['error'] ?? null);
        self::assertSame(0, $this->countRows('oauth2_device_code'));
    }

    public function test_the_metadata_advertises_the_device_endpoint(): void
    {
        $this->browser->request(Request::METHOD_GET, '/.well-known/oauth-authorization-server');

        $metadata = $this->json();
        self::assertSame($this->issuer().'/oauth/device-authorization', $metadata['device_authorization_endpoint'] ?? null);
        self::assertIsArray($metadata['grant_types_supported'] ?? null);
        self::assertContains(self::DEVICE_GRANT, $metadata['grant_types_supported']);
    }

    /** @return array{device_code: string, user_code: string, verification_uri: string, verification_uri_complete: string, interval: int, expires_in: int} */
    private function startDeviceFlow(): array
    {
        $this->browser->request(Request::METHOD_POST, '/oauth/device-authorization', [
            'client_id' => self::CLIENT_ID,
            'scope' => 'agent',
        ]);
        self::assertResponseIsSuccessful();
        $start = $this->json();
        self::assertIsString($start['device_code'] ?? null);
        self::assertIsString($start['user_code'] ?? null);
        self::assertIsString($start['verification_uri'] ?? null);
        self::assertIsString($start['verification_uri_complete'] ?? null);
        self::assertIsInt($start['interval'] ?? null);
        self::assertIsInt($start['expires_in'] ?? null);

        return [
            'device_code' => $start['device_code'],
            'user_code' => $start['user_code'],
            'verification_uri' => $start['verification_uri'],
            'verification_uri_complete' => $start['verification_uri_complete'],
            'interval' => $start['interval'],
            'expires_in' => $start['expires_in'],
        ];
    }

    /** The flag SameOriginCsrfTokenManager keeps once a session has double-submitted. */
    private function markSessionAsDoubleSubmitting(): void
    {
        $session = $this->browser->getRequest()->getSession();
        $session->set('csrf-token', 2 | (2 << 8));
        $session->save();
    }

    /** @return array<string, mixed> */
    private function poll(string $deviceCode): array
    {
        return OAuthScenario::postToken($this->browser, [
            'grant_type' => self::DEVICE_GRANT,
            'client_id' => self::CLIENT_ID,
            'device_code' => $deviceCode,
        ]);
    }

    private function verificationPath(string $uri): string
    {
        $path = (string) parse_url($uri, \PHP_URL_PATH);
        $query = parse_url($uri, \PHP_URL_QUERY);

        return \is_string($query) ? $path.'?'.$query : $path;
    }

    private function callAgentApi(string $bearer): int
    {
        $this->browser->request(Request::METHOD_GET, '/api/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);

        return $this->browser->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function issuer(): string
    {
        $url = static::getContainer()->getParameter('app.url');
        self::assertIsString($url);

        return rtrim($url, '/');
    }

    private function countRows(string $table): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }
}
