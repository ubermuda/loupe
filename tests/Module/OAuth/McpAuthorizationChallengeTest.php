<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth;

use App\Tests\Support\OAuthScenario;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/** MCP authorization discovery: the 401 challenge and RFC 9728 metadata. */
final class McpAuthorizationChallengeTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
    }

    public function test_an_anonymous_mcp_request_gets_a_401_that_points_at_the_metadata(): void
    {
        $this->postMcp(null);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            'Bearer resource_metadata="'.$this->issuer().'/.well-known/oauth-protected-resource/mcp", scope="mcp"',
            $this->browser->getResponse()->headers->get('WWW-Authenticate'),
        );
        self::assertSame('{"error":"unauthorized"}', $this->browser->getResponse()->getContent());
    }

    public function test_a_bad_bearer_on_mcp_gets_invalid_token(): void
    {
        $this->postMcp(str_repeat('a', 64));

        self::assertResponseStatusCodeSame(401);
        self::assertSame(
            'Bearer error="invalid_token", resource_metadata="'.$this->issuer().'/.well-known/oauth-protected-resource/mcp", scope="mcp"',
            $this->browser->getResponse()->headers->get('WWW-Authenticate'),
        );
    }

    public function test_a_token_without_the_mcp_scope_gets_insufficient_scope(): void
    {
        $scenario = new OAuthScenario(static::getContainer());
        $scenario->createClient();
        $user = $scenario->createUser('riley@example.com');
        $project = $scenario->createProject($user, 'Riley site');

        $this->postMcp($scenario->accessTokenFor($this->browser, $user, 'site-review', $project));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            'Bearer error="insufficient_scope", resource_metadata="'.$this->issuer().'/.well-known/oauth-protected-resource/mcp", scope="mcp"',
            $this->browser->getResponse()->headers->get('WWW-Authenticate'),
        );
        self::assertSame(['error' => 'insufficient_scope'], json_decode((string) $this->browser->getResponse()->getContent(), true));
    }

    public function test_the_api_keeps_its_plain_401(): void
    {
        $this->browser->request(Request::METHOD_GET, '/api/projects');

        self::assertResponseStatusCodeSame(401);
        self::assertFalse($this->browser->getResponse()->headers->has('WWW-Authenticate'));
        self::assertSame('{"error":"unauthorized"}', $this->browser->getResponse()->getContent());
    }

    #[DataProvider('metadataPaths')]
    public function test_it_serves_rfc_9728_metadata_to_an_anonymous_client(string $path): void
    {
        $this->browser->request(Request::METHOD_GET, $path);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->browser->getResponse()->headers->has('Set-Cookie'), 'the endpoint is stateless');
        self::assertSame([
            'resource' => $this->issuer().'/mcp',
            'authorization_servers' => [$this->issuer()],
            'scopes_supported' => ['mcp'],
            'bearer_methods_supported' => ['header'],
        ], json_decode((string) $this->browser->getResponse()->getContent(), true));
    }

    /** @return iterable<string, array{string}> */
    public static function metadataPaths(): iterable
    {
        yield 'path-specific' => ['/.well-known/oauth-protected-resource/mcp'];
        yield 'root' => ['/.well-known/oauth-protected-resource'];
    }

    private function postMcp(?string $bearer): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $bearer) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }

        $this->browser->request(Request::METHOD_POST, '/mcp', server: $server, content: self::INIT);
    }

    private function issuer(): string
    {
        $url = static::getContainer()->getParameter('app.url');
        self::assertIsString($url);

        return rtrim($url, '/');
    }
}
