<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class NavAndMcpCardTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_authenticated_user_sees_nav_links_on_dashboard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'navtest', 'navtest@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/documents');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/documents"]');
        self::assertSelectorExists('a[href="/account/api-tokens"]');
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function test_authenticated_user_sees_nav_links_on_api_tokens_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'navtest2', 'navtest2@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/documents"]');
        self::assertSelectorExists('a[href="/account/api-tokens"]');
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function test_api_tokens_page_renders_mcp_card_with_endpoint(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'mcptest', 'mcptest@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account/api-tokens');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/mcp', $content, 'MCP card must contain the endpoint path /mcp');
    }

    public function test_login_page_does_not_show_nav(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.bp-sidebar');
    }
}
