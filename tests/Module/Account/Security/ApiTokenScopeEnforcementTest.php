<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Security;

use App\Module\Account\Entity\ApiToken;
use App\Module\Account\Entity\ApiTokenScope;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiTokenScopeEnforcementTest extends WebTestCase
{
    private const string INIT = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}';

    public function test_role_mapping(): void
    {
        self::assertSame('ROLE_API_MCP', ApiTokenScope::Mcp->role());
        self::assertSame('ROLE_API_SITE_REVIEW', ApiTokenScope::SiteReview->role());
    }

    public function test_site_review_token_is_forbidden_on_mcp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issueToken($em, ApiTokenScope::SiteReview);

        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::INIT);

        self::assertResponseStatusCodeSame(403);
    }

    public function test_mcp_token_is_not_denied_on_mcp(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $raw = $this->issueToken($em, ApiTokenScope::Mcp);

        $client->request(Request::METHOD_POST, '/mcp', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$raw,
            'CONTENT_TYPE' => 'application/json',
        ], content: self::INIT);

        self::assertNotSame(401, $client->getResponse()->getStatusCode());
        self::assertNotSame(403, $client->getResponse()->getStatusCode());
    }

    private function issueToken(EntityManagerInterface $em, ApiTokenScope $scope): string
    {
        $user = new User(username: 'u'.$scope->value, fullName: 'U', email: $scope->value.'@example.com', password: 'x');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        [$token, $raw] = ApiToken::issue($user, 'tok', $scope);
        $em->persist($token);
        $em->flush();

        return $raw;
    }
}
