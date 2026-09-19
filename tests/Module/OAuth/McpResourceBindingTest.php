<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use App\Tests\Support\OAuthScenario;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** RFC 8707: an mcp token is bound to the MCP endpoint, and nothing else takes it. */
final class McpResourceBindingTest extends WebTestCase
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

    public function test_an_mcp_token_names_the_mcp_endpoint_as_its_audience_through_refresh(): void
    {
        $tokens = $this->scenario->grantTokens($this->browser, $this->user, $this->project);

        self::assertSame($this->mcpUri(), $this->audienceOf($tokens['access_token']));
        self::assertSame(200, $this->callMcp($tokens['access_token']));

        $refreshed = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'refresh_token',
            'client_id' => OAuthScenario::CLIENT_ID,
            'refresh_token' => $tokens['refresh_token'],
        ]);
        self::assertIsString($refreshed['access_token'] ?? null);
        self::assertSame($this->mcpUri(), $this->audienceOf($refreshed['access_token']));
    }

    public function test_the_resource_may_differ_in_the_case_of_scheme_and_host(): void
    {
        $upper = str_replace(['http://localhost'], ['HTTP://LOCALHOST'], $this->mcpUri());
        $tokens = $this->scenario->grantTokens($this->browser, $this->user, $this->project, ['resource' => $upper], ['resource' => $upper]);

        self::assertSame($this->mcpUri(), $this->audienceOf($tokens['access_token']));
    }

    public function test_a_token_for_another_audience_is_refused_on_mcp(): void
    {
        $tokens = $this->scenario->grantTokens($this->browser, $this->user, $this->project);
        $claims = OAuthScenario::claimsOf($tokens['access_token']);

        $forged = $this->forge($claims, OAuthScenario::CLIENT_ID);
        self::assertSame(401, $this->callMcp($forged));
        self::assertStringContainsString('error="invalid_token"', (string) $this->browser->getResponse()->headers->get('WWW-Authenticate'));

        self::assertSame(200, $this->callMcp($this->forge($claims, $this->mcpUri())), 'the forger itself works, so the audience alone is refused');
    }

    public function test_authorize_refuses_another_resource_with_invalid_target(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('mcp', ['resource' => 'https://evil.example/mcp']));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_target', $query['error']);
        self::assertSame('state-123', $query['state']);
        self::assertSame($this->issuer(), $query['iss']);
    }

    public function test_authorize_refuses_the_mcp_resource_for_another_scope(): void
    {
        $this->browser->loginUser($this->user);
        $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl('agent', ['resource' => $this->mcpUri()]));

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_target', $query['error']);
    }

    public function test_authorize_refuses_a_repeated_resource(): void
    {
        $this->browser->loginUser($this->user);
        $url = $this->scenario->authorizeUrl('mcp', ['resource' => $this->mcpUri()]).'&resource='.rawurlencode('https://evil.example/mcp');
        $this->browser->request(Request::METHOD_GET, $url);

        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        self::assertSame('invalid_target', $query['error']);
    }

    public function test_the_token_endpoint_refuses_another_resource(): void
    {
        $this->browser->loginUser($this->user);
        $crawler = $this->browser->request(Request::METHOD_GET, $this->scenario->authorizeUrl());
        $this->browser->submit($crawler->selectButton('consent_form_approve')->form([
            'consent_form[project]' => (string) $this->project->id,
        ]));
        $query = OAuthScenario::redirectQuery((string) $this->browser->getResponse()->headers->get('Location'));
        $this->browser->restart();

        $response = OAuthScenario::postToken($this->browser, [
            'grant_type' => 'authorization_code',
            'client_id' => OAuthScenario::CLIENT_ID,
            'redirect_uri' => OAuthScenario::REDIRECT_URI,
            'code' => $query['code'],
            'code_verifier' => $this->scenario->codeVerifier,
            'resource' => 'https://evil.example/mcp',
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_target', $response['error']);
    }

    /** @param array<string, mixed> $claims */
    private function forge(array $claims, string $audience): string
    {
        $key = file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/tests/Support/oauth/private.pem') ?: throw new \LogicException('test key missing');
        $config = Configuration::forAsymmetricSigner(new Sha256(), InMemory::plainText($key), InMemory::plainText('unused'));
        $jti = $claims['jti'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (!\is_string($jti) || '' === $jti || !\is_string($sub) || '' === $sub || '' === $audience) {
            throw new \LogicException('the token to copy lacks jti or sub');
        }

        return $config->builder()
            ->permittedFor($audience)
            ->identifiedBy($jti)
            ->relatedTo($sub)
            ->issuedAt(new \DateTimeImmutable('-1 second'))
            ->canOnlyBeUsedAfter(new \DateTimeImmutable('-1 second'))
            ->expiresAt(new \DateTimeImmutable('+10 minutes'))
            ->withClaim('scopes', $claims['scopes'] ?? [])
            ->getToken($config->signer(), $config->signingKey())
            ->toString();
    }

    private function audienceOf(string $jwt): string
    {
        $aud = OAuthScenario::claimsOf($jwt)['aud'] ?? null;
        if (!\is_array($aud)) {
            $aud = [$aud];
        }
        self::assertCount(1, $aud);
        self::assertIsString($aud[0]);

        return $aud[0];
    }

    private function callMcp(string $bearer): int
    {
        $this->browser->request(Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$bearer,
        ], content: self::INIT);

        return $this->browser->getResponse()->getStatusCode();
    }

    private function mcpUri(): string
    {
        return $this->issuer().'/mcp';
    }

    private function issuer(): string
    {
        $url = static::getContainer()->getParameter('app.url');
        self::assertIsString($url);

        return rtrim($url, '/');
    }
}
