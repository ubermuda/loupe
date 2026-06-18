<?php

declare(strict_types=1);

namespace App\Tests\Module\Mcp;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class McpEndpointAuthTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_missing_token_is_rejected(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/_mcp', server: ['CONTENT_TYPE' => 'application/json'], content: self::INIT);
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function test_valid_token_authenticates(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'agent', fullName: 'Agent', email: 'agent@example.com', password: 'hashed-placeholder');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        [$token, $raw] = ApiToken::issue($user, 'test');
        $em->persist($user);
        $em->persist($token);
        $em->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/_mcp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
        ], content: self::INIT);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }
}
