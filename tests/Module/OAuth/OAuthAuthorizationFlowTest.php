<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\ProjectDeleter;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Manager\ClientManagerInterface;
use League\Bundle\OAuth2ServerBundle\ValueObject\Scope;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class OAuthAuthorizationFlowTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->scenario->createClient();
        $this->user = $this->scenario->createUser('riley@example.com');
        $this->project = $this->scenario->createProject($this->user, 'Riley site');
    }

    public function test_authorize_consent_token_call_refresh_and_revoke(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        self::assertResponseIsSuccessful();
        $page = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString(OAuthScenario::CLIENT_NAME, $page);
        self::assertStringContainsString('client.example', $page);
        self::assertStringContainsString('Riley site', $page);

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form([
            'consent_form[project]' => (string) $this->project->id,
        ]));

        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith(OAuthScenario::REDIRECT_URI.'?', $location);
        $query = OAuthScenario::redirectQuery($location);
        self::assertSame('state-123', $query['state']);
        self::assertSame($this->issuer(), $query['iss']);
        self::assertNotEmpty($query['code']);

        $this->browser->restart();
        $tokens = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'authorization_code',
            'client_id' => OAuthScenario::CLIENT_ID,
            'redirect_uri' => OAuthScenario::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $this->scenario->codeVerifier,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('Bearer', $tokens['token_type']);
        self::assertIsString($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);

        self::assertSame(200, $this->callMcp($tokens['access_token']));
        self::assertToolSeesBoundProject($tokens['access_token']);

        $refreshed = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsString($refreshed['access_token']);
        self::assertIsString($refreshed['refresh_token']);
        self::assertNotSame($tokens['refresh_token'], $refreshed['refresh_token']);

        self::assertSame(401, $this->callMcp($tokens['access_token']), 'a refresh revokes the old access token');
        self::assertSame(200, $this->callMcp($refreshed['access_token']));

        // The grace window accepts the rotated token once, so a client whose
        // refresh response was lost recovers. The second reuse is refused.
        $recovered = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertIsString($recovered['refresh_token']);
        self::assertNotSame($tokens['refresh_token'], $recovered['refresh_token']);

        $replay = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_grant', $replay['error']);

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, '/account/connected-apps');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-connected-app="'.OAuthScenario::CLIENT_ID.'"]', OAuthScenario::CLIENT_NAME);
        self::assertSelectorTextContains('[data-connected-app="'.OAuthScenario::CLIENT_ID.'"]', 'Riley site');

        $this->browser->submit($crawler->filter('[data-connected-app="'.OAuthScenario::CLIENT_ID.'"] form')->form());
        self::assertResponseRedirects('/account/connected-apps');
        $this->browser->followRedirect();
        self::assertSelectorNotExists('[data-connected-app="'.OAuthScenario::CLIENT_ID.'"]');

        $this->browser->restart();
        self::assertSame(401, $this->callMcp($refreshed['access_token']), 'revoke kills the access token');
        $afterRevoke = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $recovered['refresh_token'],
        ]);
        self::assertSame('invalid_grant', $afterRevoke['error']);
    }

    /**
     * A session that has double-submitted once must double-submit on every later
     * stateless form. An Allow click that lands before the CSRF script loads
     * sends no double-submit, so the consent form must not use a stateless token.
     */
    public function test_consent_works_before_the_csrf_script_has_loaded(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        $session = $this->browser->getRequest()->getSession();
        $session->set('csrf-token', 2 | (2 << 8));
        $session->save();

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form([
            'consent_form[project]' => (string) $this->project->id,
        ]));

        self::assertResponseRedirects();
        self::assertNotEmpty(OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'))['code'] ?? null);
    }

    /** The device page draws the same row from the same partial, so both pages must keep it. */
    public function test_the_scope_row_states_the_scope_and_its_bounds(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('agent'));

        $row = $crawler->filter('[data-testid="oauth-consent-scope"]')->closest('.lp-consent__row');
        self::assertNotNull($row);
        self::assertStringContainsString('It can list your projects', $row->text());
        self::assertStringContainsString('It cannot reach your account', $row->text());
    }

    public function test_the_project_picker_gates_the_allow_button(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());

        self::assertSame('require-choice', $crawler->filter('form.lp-consent__form')->attr('data-controller'));
        self::assertSame('choice', $crawler->filter('#consent_form_project')->attr('data-require-choice-target'));
        self::assertSame('submit', $crawler->filter('#consent_form_approve')->attr('data-require-choice-target'));

        // The agent scope picks no project, so nothing gates its button.
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('agent'));
        self::assertCount(0, $crawler->filter('#consent_form_project'));
        self::assertSame('submit', $crawler->filter('#consent_form_approve')->attr('data-require-choice-target'));
    }

    public function test_consent_refuses_a_forged_csrf_token(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        $form = $crawler->selectButton('consent_form_approve')->form();
        $values = $form->getPhpValues();
        $values['consent_form']['project'] = (string) $this->project->id;
        $values['consent_form']['_token'] = str_repeat('a', 43);
        $this->browser->request(Request::METHOD_POST, $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
    }

    public function test_a_static_token_still_authenticates_beside_oauth(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$token, $raw] = ApiToken::issue($this->user, 'static', ApiTokenScope::Mcp);
        $this->project->mcpToken = $token;
        $em->persist($token);
        $em->flush();

        self::assertSame(200, $this->callMcp($raw));
    }

    public function test_consent_refuses_a_project_the_user_does_not_own(): void
    {
        $stranger = $this->scenario->createUser('stranger@example.com');
        $strangersProject = $this->scenario->createProject($stranger, 'Stranger site');

        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Stranger site', (string) $this->browser->getResponse()->getContent());

        $form = $crawler->selectButton('consent_form_approve')->form();
        $values = $form->getPhpValues();
        $values['consent_form']['project'] = (string) $strangersProject->id;
        $this->browser->request(Request::METHOD_POST, $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
    }

    public function test_a_client_cannot_request_a_project_scope_directly(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp project:'.$this->project->id));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_scope', $query['error']);
        self::assertSame($this->issuer(), $query['iss']);
    }

    /**
     * One credential reaches several firewalls, so a grant may carry several
     * base scopes. The client's own registered list is what bounds them.
     */
    public function test_several_registered_base_scopes_are_accepted(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp agent'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="oauth-consent-scope"]');
    }

    /**
     * The client asks for every project and the screen says so, in place of the
     * picker. A picker there would let the person narrow a grant the client
     * needs whole, and `loupe mcp` would then reach one repository only.
     */
    public function test_an_all_projects_request_says_so_and_offers_no_picker(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $clients = static::getContainer()->get(ClientManagerInterface::class);
        self::assertInstanceOf(ClientManagerInterface::class, $clients);
        $client = $clients->find(OAuthScenario::CLIENT_ID);
        self::assertNotNull($client);
        $client->setScopes(new Scope('mcp'), new Scope('projects'));
        $clients->save($client);

        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp projects'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="oauth-consent-all-projects"]');
        self::assertSelectorNotExists('.lp-consent__picker-label');
    }

    public function test_a_scope_the_client_is_not_registered_for_is_refused(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp projects'));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_scope', $query['error'], 'the scenario client is registered for mcp, site-review and agent, never projects');
    }

    public function test_plain_pkce_is_refused(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', [
            'code_challenge' => $this->scenario->codeVerifier,
            'code_challenge_method' => 'plain',
        ]));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_request', $query['error']);
        self::assertSame($this->issuer(), $query['iss']);
    }

    public function test_a_public_client_must_send_a_code_challenge(): void
    {
        $this->browser->loginUser($this->user);
        $url = $this->scenario->authorizeUrl();
        $url = (string) preg_replace('/&code_challenge=[^&]*&code_challenge_method=S256/', '', $url);
        $this->browser->request(Request::METHOD_GET, $url);

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('invalid_request', (string) $this->browser->getResponse()->getContent());
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
    }

    public function test_an_unregistered_redirect_uri_gets_no_redirect(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', ['redirect_uri' => 'https://evil.example/callback']));

        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
    }

    public function test_a_loopback_redirect_matches_on_any_port_through_the_code_exchange(): void
    {
        $this->scenario->createClient('native-client', ['http://localhost/callback']);
        $redirectUri = 'http://localhost:53712/callback';

        $tokens = $this->scenario->grantTokens($this->browser, $this->user, $this->project, ['client_id' => 'native-client', 'redirect_uri' => $redirectUri]);

        self::assertSame(200, $this->callMcp($tokens['access_token']));
    }

    public function test_a_public_redirect_on_another_port_gets_no_redirect(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', ['redirect_uri' => 'https://client.example:444/callback']));

        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
    }

    public function test_deny_redirects_with_access_denied(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_deny')->form());

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('access_denied', $query['error']);
        self::assertSame('state-123', $query['state']);
        self::assertSame($this->issuer(), $query['iss']);
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
    }

    public function test_an_anonymous_visitor_is_sent_to_login(): void
    {
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());

        self::assertResponseRedirects('/login');
    }

    public function test_a_suspended_user_cannot_authorize_or_refresh(): void
    {
        $tokens = $this->grantTokens();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->user->id) ?? throw new \LogicException('user fixture missing');
        $user->suspendedAt = new \DateTimeImmutable();
        $em->flush();

        self::assertSame(401, $this->callMcp($tokens['access_token']));
        $refresh = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertSame('invalid_grant', $refresh['error']);

        $this->browser->loginUser($user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        self::assertFalse($this->browser->getResponse()->isSuccessful());
        self::assertSame(0, $this->countRows('oauth2_authorization_code', revoked: false));
    }

    public function test_the_agent_scope_binds_no_project(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('agent'));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[name="consent_form[project]"]');

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->restart();
        $tokens = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'authorization_code',
            'client_id' => OAuthScenario::CLIENT_ID,
            'redirect_uri' => OAuthScenario::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $this->scenario->codeVerifier,
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsString($tokens['access_token'] ?? null);

        $this->browser->request(Request::METHOD_GET, '/api/projects', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']]);
        self::assertResponseIsSuccessful();
        $this->browser->request(Request::METHOD_POST, '/mcp', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['access_token']], content: self::INIT);
        self::assertResponseStatusCodeSame(403);
    }

    public function test_deleting_the_project_revokes_its_grants(): void
    {
        $tokens = $this->grantTokens();
        self::assertSame(1, $this->countRows('oauth2_access_token'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(ProjectDeleter::class)->delete(
            $em->find(Project::class, $this->project->id) ?? throw new \LogicException('project fixture missing'),
        );

        self::assertSame(0, $this->countRows('oauth2_access_token'));
        self::assertSame(0, $this->countRows('oauth2_refresh_token'));
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
        self::assertSame(401, $this->callMcp($tokens['access_token']));
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function grantTokens(): array
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_approve')->form([
            'consent_form[project]' => (string) $this->project->id,
        ]));
        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->restart();
        $tokens = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'authorization_code',
            'client_id' => OAuthScenario::CLIENT_ID,
            'redirect_uri' => OAuthScenario::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $this->scenario->codeVerifier,
        ]);
        self::assertIsString($tokens['access_token'] ?? null);
        self::assertIsString($tokens['refresh_token'] ?? null);

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']];
    }

    private function callMcp(string $bearer): int
    {
        $this->browser->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        ], content: self::INIT);

        return $this->browser->getResponse()->getStatusCode();
    }

    private function assertToolSeesBoundProject(string $bearer): void
    {
        $sessionId = $this->browser->getResponse()->headers->get('Mcp-Session-Id');
        $this->browser->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"document_list","arguments":{}}}');
        $body = (string) $this->browser->getResponse()->getContent();
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('not bound to a project', $body);
        self::assertStringContainsString('"documents"', $body);
    }

    private function issuer(): string
    {
        $url = static::getContainer()->getParameter('app.url');
        self::assertIsString($url);

        return rtrim($url, '/');
    }

    private function countRows(string $table, ?bool $revoked = null): int
    {
        $sql = 'SELECT COUNT(*) FROM '.$table.(null === $revoked ? '' : ' WHERE revoked = '.($revoked ? 'true' : 'false'));

        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne($sql);
    }
}
