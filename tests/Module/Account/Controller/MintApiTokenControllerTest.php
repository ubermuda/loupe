<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\ApiTokenRepository;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class MintApiTokenControllerTest extends WebTestCase
{
    private const string MCP_INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_minting_shows_the_raw_token_once_and_lists_it_afterwards(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Minter', 'minter@example.com');

        $client->loginUser($user);
        $raw = $this->mint($client, 'Laptop CLI');

        // A 64-character hex string: the value ApiToken::issue() generates.
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $raw);

        $repo = static::getContainer()->get(ApiTokenRepository::class);
        $tokens = $repo->findActiveByOwner($user);
        self::assertCount(1, $tokens);
        self::assertSame('Laptop CLI', $tokens[0]->label);
        // The form offers no scope: an account-level token is always site-review.
        self::assertSame(ApiTokenScope::SiteReview, $tokens[0]->scope);
        // Only the hash and the tail are kept; the raw value is not recoverable.
        self::assertSame(hash('sha256', $raw), $tokens[0]->tokenHash);
        self::assertSame(substr($raw, -4), $tokens[0]->tokenTail);
    }

    public function test_a_second_page_load_does_not_show_the_raw_token_again(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Reloader', 'reloader@example.com');

        $client->loginUser($user);
        $raw = $this->mint($client, 'Second load');

        // Guard: the assertion below would also pass on a page that rendered
        // nothing at all, so pin that the row is really there first.
        $crawler = $client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-token-id]'));

        self::assertSelectorNotExists('[data-testid="minted-api-token"]');
        self::assertStringNotContainsString($raw, (string) $client->getResponse()->getContent());
    }

    public function test_a_minted_token_authenticates_against_its_scoped_endpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Streamer', 'streamer@example.com');

        $client->loginUser($user);
        $raw = $this->mint($client, 'Stream token');

        // Drop the session so the Bearer token is the only credential in play.
        // ^/api/site-review needs ROLE_API_SITE_REVIEW, which this scope carries.
        $client->getCookieJar()->clear();
        $client->request(Request::METHOD_GET, '/api/site-review/stream', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ]);

        // 400 from the controller itself, not 401 or 403 from the firewall: the
        // token authenticated and carried the role, and the request then failed
        // on the missing ?site= handle. Asserting the body rather than "not 401"
        // keeps the test from passing on an unrelated rejection.
        self::assertResponseStatusCodeSame(400);
        self::assertJsonStringEqualsJsonString(
            '{"error":"missing_site_parameter"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function test_a_site_review_token_is_refused_on_the_mcp_endpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Reviewer', 'reviewer@example.com');

        $client->loginUser($user);
        $raw = $this->mint($client, 'Review token');

        $client->getCookieJar()->clear();
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::MCP_INIT);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_the_form_offers_no_scope_because_an_unbound_mcp_token_fails_every_tool_call(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Unbound', 'unbound@example.com');

        // Issued directly, because the form cannot produce this shape: an MCP
        // token owned by an account rather than bound to a project.
        [$token, $raw] = ApiToken::issue($user, 'Unbound agent token', ApiTokenScope::Mcp);
        $em->persist($token);
        $em->flush();

        // It authenticates and completes the handshake, which is what made this
        // easy to miss.
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::MCP_INIT);
        self::assertResponseIsSuccessful();
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');

        // A real tool call then fails, because every tool resolves its project
        // through projects.mcp_token_id and nothing binds an account-level token.
        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"tag_list","arguments":{}}}');

        // The transport still answers 200; the refusal rides in the JSON-RPC
        // result, so a status assertion alone would miss it entirely.
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);
        self::assertTrue($body['result']['isError']);
        self::assertSame(
            'MCP token is not bound to a project. Mint a project token from the Connect page.',
            $body['result']['content'][0]['text'],
        );
    }

    /**
     * The route carries no #[CsrfToken] attribute: protection comes from the
     * form component, which issues and checks its own _token. That makes the
     * guard a framework default rather than something visible at the call site,
     * so it is pinned here.
     */
    public function test_a_post_without_a_csrf_token_mints_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Forger', 'forger@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_POST, '/account/api-tokens', [
            'mint_api_token_form' => ['label' => 'Forged'],
        ]);

        $repo = static::getContainer()->get(ApiTokenRepository::class);
        self::assertSame([], $repo->findActiveByOwner($user), 'a submit with no _token must mint nothing');
    }

    public function test_a_blank_label_is_refused_and_mints_nothing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'Blank', 'blank@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');
        $client->submitForm('Create token', ['mint_api_token_form[label]' => '   ']);

        self::assertResponseStatusCodeSame(422);
        // The settings page is re-rendered with the bound form, not redirected away,
        // and the failure lands on the field rather than in a lossy flash.
        self::assertSelectorTextContains('[data-testid="mint-api-token-form"]', 'should not be blank');
        self::assertSelectorNotExists('[data-testid="minted-api-token"]');

        $repo = static::getContainer()->get(ApiTokenRepository::class);
        self::assertSame(0, $repo->countActiveByOwner($user));
    }

    /** Submits the mint form and returns the raw token the page shows once. */
    private function mint(KernelBrowser $client, string $label): string
    {
        $client->request(Request::METHOD_GET, '/account');
        self::assertResponseIsSuccessful();

        $client->submitForm('Create token', ['mint_api_token_form[label]' => $label]);
        self::assertResponseRedirects('/account');

        $crawler = $client->followRedirect();
        $secret = $crawler->filter('[data-testid="minted-api-token"] code');
        self::assertCount(1, $secret);

        $raw = trim($secret->text());
        self::assertNotSame('', $raw);

        return $raw;
    }

    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $name, string $email): User
    {
        $user = new User(fullName: $name, email: $email, password: 'hashed-password-placeholder');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
