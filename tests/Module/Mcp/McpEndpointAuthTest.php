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
            'document_create',
            'document_get',
            'document_get_review',
            'document_highlight',
            'document_list',
            'document_mark_comment_addressed',
            'document_rename',
            'document_reply_to_comment',
            'document_revise',
            'site_review_get',
            'site_review_mark_comment_addressed',
        ], $names);
    }

    public function test_request_on_an_unlisted_host_is_rejected_with_a_self_diagnosing_body(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_HOST' => 'evil.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            "Forbidden: Invalid Host header.\nThe Host header of this request (evil.example.com) is not listed in MCP_ALLOWED_HOSTS, so the MCP endpoint's DNS-rebinding protection rejected it. This is not an authentication failure: add the hostname agents use to reach this instance to MCP_ALLOWED_HOSTS (comma-separated, no port) and restart the app.\n",
            (string) $response->getContent(),
        );
    }

    public function test_request_with_an_unlisted_origin_is_rejected_with_a_self_diagnosing_body(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        // Origin is checked before Host and short-circuits it, so this is a
        // second rejection path an operator can land on.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(
            "Forbidden: Invalid Origin header.\nThe Origin header of this request (https://evil.example.com) is not listed in MCP_ALLOWED_HOSTS, so the MCP endpoint's DNS-rebinding protection rejected it. This is not an authentication failure: add the hostname agents use to reach this instance to MCP_ALLOWED_HOSTS (comma-separated, no port) and restart the app.\n",
            (string) $response->getContent(),
        );
    }

    public function test_rejected_origin_is_echoed_truncated(): void
    {
        $client = static::createClient();
        $raw = $this->persistValidToken();

        // The echoed value is attacker-controlled, so it is bounded. (Control
        // characters are stripped as well, but HttpFoundation removes those
        // before the listener ever sees the header, so only the length cap is
        // reachable over HTTP.)
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/mcp', server: [
            'HTTP_ORIGIN' => 'https://'.str_repeat('a', 200).'.example.com',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString(
            // 128 characters kept: "https://" plus 120 of the a's.
            '(https://'.str_repeat('a', 120).'...)',
            (string) $response->getContent(),
        );
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
