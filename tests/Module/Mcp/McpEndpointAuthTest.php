<?php

declare(strict_types=1);

namespace App\Tests\Module\Mcp;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpEndpointAuthTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_missing_token_is_rejected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_invalid_token_is_rejected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer invalid-token-value',
        ], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_revoked_token_is_rejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'agent-revoked', fullName: 'Agent', email: 'agent-revoked@example.com', password: 'hashed-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        [$token, $raw] = ApiToken::issue($user, 'test', ApiTokenScope::Mcp);
        $token->revoke();
        $em->persist($user);
        $em->persist($token);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_valid_token_authenticates(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_request_on_the_app_host_is_not_blocked_by_dns_rebinding(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        // The MCP SDK's DNS-rebinding protection allows only localhost by default;
        // MCP_ALLOWED_HOSTS adds the app host so real clients can connect.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_HOST' => 'loupe.dev.localhost',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    public function test_tools_list_returns_every_registered_tool(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'HTTP_MCP_SESSION_ID' => $sessionId,
        ], content: '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}');
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true);
        $names = array_column($body['result']['tools'], 'name');
        sort($names);

        self::assertSame([
            'address_site_review_comments',
            'create_document',
            'get_document',
            'get_review',
            'get_site_review',
            'list_documents',
            'revise_document',
        ], $names);
    }

    public function test_request_on_an_unlisted_host_is_rejected(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_HOST' => 'evil.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    private function persistValidToken(): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'agent', fullName: 'Agent', email: 'agent@example.com', password: 'hashed-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        [$token, $raw] = ApiToken::issue($user, 'test', ApiTokenScope::Mcp);
        $em->persist($user);
        $em->persist($token);
        $em->flush();

        return $raw;
    }
}
