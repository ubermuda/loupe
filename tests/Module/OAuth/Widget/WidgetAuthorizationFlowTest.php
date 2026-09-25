<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Widget;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Widget\WidgetClient;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Tests\Module\Board\BoardColumnFixtures;
use App\Tests\Support\OAuthScenario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class WidgetAuthorizationFlowTest extends WebTestCase
{
    use BoardColumnFixtures;

    private const string SITE = 'https://shop.example.com';

    private KernelBrowser $browser;
    private OAuthScenario $scenario;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->scenario = new OAuthScenario(static::getContainer());
        $this->owner = $this->scenario->createUser('widget-owner@example.com');
        $this->project = $this->scenario->createProject($this->owner, 'Shop');
        $this->project->allowedOrigins = [self::SITE];
        // A widget note becomes a card, so the board needs its columns.
        $this->seedColumns($this->project);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    public function test_the_popup_flow_posts_the_code_to_the_site_and_the_token_reaches_the_widget_api(): void
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="oauth-consent-project"]', 'Shop');
        self::assertSelectorTextContains('[data-testid="oauth-consent-site"]', self::SITE);
        self::assertSelectorNotExists('[name="consent_form[project]"]');

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith($this->callbackUrl().'?', $location);

        $message = $this->followToCallback();
        self::assertSame(self::SITE, $message['targetOrigin']);
        self::assertSame('widget-state', $message['payload']['state']);
        self::assertSame($this->issuer(), $message['payload']['iss']);
        self::assertSame('loupe-site-review-oauth', $message['payload']['type']);
        $code = $message['payload']['code'];
        self::assertIsString($code);

        $this->browser->restart();
        $tokens = $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => WidgetClient::ID,
            'redirect_uri' => $this->callbackUrl(),
            'code' => $code,
            'code_verifier' => $this->scenario->codeVerifier,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame(self::SITE, $this->browser->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertIsString($tokens['access_token']);
        self::assertIsString($tokens['refresh_token']);

        $this->browser->request(Request::METHOD_GET, '/api/site-review/review', server: $this->bearer($tokens['access_token']));
        self::assertResponseIsSuccessful();

        $this->browser->request(Request::METHOD_POST, '/api/board/feedback', server: [
            ...$this->bearer($tokens['access_token']),
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['body' => 'The button is hard to see', 'url' => self::SITE.'/checkout', 'target' => ['newCard' => new \stdClass()]]));
        self::assertResponseStatusCodeSame(201);
        $comments = static::getContainer()->get(EntityManagerInterface::class)->getRepository(SiteReviewComment::class)->findBy(['body' => 'The button is hard to see']);
        self::assertCount(1, $comments);
        self::assertSame((string) $this->project->id, (string) $comments[0]->project->id);

        $refreshed = $this->postToken([
            'grant_type' => 'refresh_token',
            'client_id' => WidgetClient::ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertIsString($refreshed['access_token']);
        $this->browser->request(Request::METHOD_GET, '/api/site-review/review', server: $this->bearer($refreshed['access_token']));
        self::assertResponseIsSuccessful();
    }

    public function test_the_widget_token_cannot_reach_the_agent_or_mcp_surface(): void
    {
        $tokens = $this->grantTokens();

        $this->browser->request(Request::METHOD_GET, '/api/projects', server: $this->bearer($tokens['access_token']));
        self::assertResponseStatusCodeSame(403);
        $this->browser->request(Request::METHOD_POST, '/mcp', server: [...$this->bearer($tokens['access_token']), 'CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_allow_button_is_live_although_the_page_has_no_picker(): void
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        // require-choice disables Allow until a project is chosen. The widget
        // fixes the project, so the page carries no choice and the button stays live.
        self::assertSame('require-choice', $crawler->filter('form.lp-consent__form')->attr('data-controller'));
        self::assertCount(0, $crawler->filter('[data-require-choice-target="choice"]'));
        $approve = $crawler->filter('#consent_form_approve');
        self::assertSame('submit', $approve->attr('data-require-choice-target'));
        self::assertNull($approve->attr('disabled'));

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        self::assertResponseRedirects();
    }

    public function test_a_wildcard_entry_covers_one_label_under_it(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->project->allowedOrigins = ['https://*.loupe.dev.localhost'];
        $em->flush();
        $worktree = 'https://agent-x1.loupe.dev.localhost';

        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['origin' => $worktree]));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="oauth-consent-site"]', $worktree);

        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        $message = $this->followToCallback();

        self::assertSame($worktree, $message['targetOrigin'], 'the callback posts to the origin that asked, not to the pattern');
        self::assertIsString($message['payload']['code']);

        $this->browser->request(Request::METHOD_OPTIONS, '/oauth/token', server: [
            'HTTP_ORIGIN' => $worktree,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertSame($worktree, $this->browser->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_wildcard_entry_covers_one_label_and_no_more(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->project->allowedOrigins = ['https://*.loupe.dev.localhost'];
        $em->flush();

        $this->browser->loginUser($this->owner);
        foreach (['https://a.b.loupe.dev.localhost', 'https://loupe.dev.localhost', 'https://evil.example'] as $origin) {
            $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['origin' => $origin]));
            self::assertResponseStatusCodeSame(403, $origin.' must not pass');
            self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
        }

        $this->browser->request(Request::METHOD_OPTIONS, '/oauth/token', server: [
            'HTTP_ORIGIN' => 'https://a.b.loupe.dev.localhost',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertFalse($this->browser->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_an_origin_missing_from_the_list_is_refused_before_consent(): void
    {
        $this->browser->loginUser($this->owner);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['origin' => 'https://evil.example']));

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
        self::assertSelectorNotExists('[data-testid="oauth-consent"]');

        $message = $this->visitCallback(['code' => 'forged', 'state' => 'widget-state']);
        self::assertNull($message['targetOrigin'], 'a refused request must leave nothing for the callback to post to');
    }

    public function test_a_user_who_does_not_own_the_project_is_refused(): void
    {
        $stranger = $this->scenario->createUser('widget-stranger@example.com');
        $this->browser->loginUser($stranger);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
        self::assertSelectorNotExists('[data-testid="oauth-consent"]');
        self::assertStringNotContainsString(self::SITE, (string) $this->browser->getResponse()->getContent());

        $this->browser->request(Request::METHOD_POST, $this->authorizeUrl(), ['consent_form' => ['approve' => '']]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
    }

    public function test_an_unknown_project_is_refused(): void
    {
        $this->browser->loginUser($this->owner);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['project' => '01a0bafa-0000-7000-8000-000000000000']));

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
    }

    public function test_the_consent_cannot_bind_another_project(): void
    {
        $other = $this->scenario->createProject($this->owner, 'Other');

        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $form = $crawler->selectButton('consent_form_approve')->form();
        $values = $form->getPhpValues();
        $values['consent_form']['project'] = (string) $other->id;
        $this->browser->request(Request::METHOD_POST, $form->getUri(), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->browser->getResponse()->headers->has('Location'));
        self::assertSame(0, $this->countRows('oauth2_authorization_code'));
    }

    public function test_the_grant_is_bound_to_the_requested_project_only(): void
    {
        $this->scenario->createProject($this->owner, 'Other');
        $tokens = $this->grantTokens();

        $scopes = (string) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT scopes FROM oauth2_access_token WHERE revoked = false');
        self::assertSame(['site-review', 'project:'.$this->project->id], explode(' ', $scopes));
        self::assertNotEmpty($tokens['access_token']);
    }

    public function test_a_widget_request_must_ask_for_the_site_review_scope(): void
    {
        $this->browser->loginUser($this->owner);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['scope' => 'mcp']));

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
    }

    public function test_a_widget_request_must_carry_a_state(): void
    {
        $this->browser->loginUser($this->owner);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl(['state' => '']));

        self::assertResponseStatusCodeSame(400);
        self::assertSelectorExists('[data-testid="oauth-widget-refused"]');
    }

    public function test_deny_posts_access_denied_to_the_site(): void
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_deny')->form());

        $message = $this->followToCallback();
        self::assertSame(self::SITE, $message['targetOrigin']);
        self::assertSame('access_denied', $message['payload']['error']);
        self::assertSame('widget-state', $message['payload']['state']);
        self::assertArrayNotHasKey('code', $message['payload']);
    }

    public function test_the_callback_posts_nothing_for_a_state_it_did_not_see(): void
    {
        $this->browser->loginUser($this->owner);
        $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());

        $message = $this->visitCallback(['code' => 'abc', 'state' => 'another-state']);

        self::assertNull($message['targetOrigin']);
    }

    public function test_the_callback_posts_once_per_state(): void
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        $this->followToCallback();

        $replay = $this->visitCallback(['code' => 'abc', 'state' => 'widget-state']);

        self::assertNull($replay['targetOrigin']);
    }

    public function test_an_origin_removed_after_consent_gets_nothing(): void
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id) ?? throw new \LogicException('project fixture missing');
        $project->allowedOrigins = [];
        $em->flush();

        self::assertNull($this->followToCallback()['targetOrigin']);
    }

    public function test_the_token_endpoint_answers_a_preflight_from_an_allowed_site_only(): void
    {
        $this->browser->request(Request::METHOD_OPTIONS, '/oauth/token', server: [
            'HTTP_ORIGIN' => self::SITE,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame(self::SITE, $this->browser->getResponse()->headers->get('Access-Control-Allow-Origin'));
        self::assertNull($this->browser->getResponse()->headers->get('Access-Control-Allow-Credentials'));

        $this->browser->request(Request::METHOD_OPTIONS, '/oauth/token', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        self::assertFalse($this->browser->getResponse()->headers->has('Access-Control-Allow-Origin'));

        $this->postToken(['grant_type' => 'refresh_token', 'client_id' => WidgetClient::ID, 'refresh_token' => 'nope'], 'https://evil.example');
        self::assertFalse($this->browser->getResponse()->headers->has('Access-Control-Allow-Origin'));
    }

    /** @param array<string, string> $overrides */
    private function authorizeUrl(array $overrides = []): string
    {
        return '/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => WidgetClient::ID,
            'redirect_uri' => $this->callbackUrl(),
            'scope' => 'site-review',
            'state' => 'widget-state',
            'code_challenge' => $this->scenario->codeChallenge,
            'code_challenge_method' => 'S256',
            'project' => (string) $this->project->id,
            'origin' => self::SITE,
            ...$overrides,
        ]);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function grantTokens(): array
    {
        $this->browser->loginUser($this->owner);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_approve')->form());
        $code = $this->followToCallback()['payload']['code'] ?? null;
        self::assertIsString($code);
        $this->browser->restart();
        $tokens = $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => WidgetClient::ID,
            'redirect_uri' => $this->callbackUrl(),
            'code' => $code,
            'code_verifier' => $this->scenario->codeVerifier,
        ]);
        self::assertIsString($tokens['access_token'] ?? null);
        self::assertIsString($tokens['refresh_token'] ?? null);

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token']];
    }

    /** @return array{targetOrigin: ?string, payload: array<string, mixed>} */
    private function followToCallback(): array
    {
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringStartsWith($this->callbackUrl().'?', $location);

        return $this->readCallback($this->browser->request(Request::METHOD_GET, $location));
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{targetOrigin: ?string, payload: array<string, mixed>}
     */
    private function visitCallback(array $query): array
    {
        return $this->readCallback($this->browser->request(Request::METHOD_GET, WidgetClient::CALLBACK_PATH.'?'.http_build_query($query)));
    }

    /** @return array{targetOrigin: ?string, payload: array<string, mixed>} */
    private function readCallback(Crawler $crawler): array
    {
        self::assertResponseIsSuccessful();
        $node = $crawler->filter('[data-testid="oauth-widget-callback"]');
        self::assertCount(1, $node);
        $target = $node->attr('data-target-origin');
        $payload = json_decode((string) $node->attr('data-message'), true);

        return [
            'targetOrigin' => null === $target || '' === $target ? null : $target,
            'payload' => \is_array($payload) ? $payload : [],
        ];
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function postToken(array $parameters, string $origin = self::SITE): array
    {
        $this->browser->request(Request::METHOD_POST, '/oauth/token', $parameters, server: [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ORIGIN' => $origin,
        ]);
        $decoded = json_decode((string) $this->browser->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ORIGIN' => self::SITE];
    }

    private function callbackUrl(): string
    {
        return WidgetClient::redirectUri($this->issuer());
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
