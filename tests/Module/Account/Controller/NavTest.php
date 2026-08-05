<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class NavTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_authenticated_user_sees_nav_links_on_projects_page(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'navtest', 'navtest@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        // Outside a project context the shell shows the brand (→ projects index)
        // and the account row's logout affordance — no scoped nav.
        self::assertSelectorExists('a[href="/projects"]');
        self::assertSelectorExists('form[action="/logout"]');
    }

    public function test_admin_link_is_only_rendered_for_admins(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'navplain', 'navplain@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a.lp-sidebar__link[href="/admin"]');

        $admin = $this->createVerifiedUser($em, 'navadmin', 'navadmin@example.com');
        $admin->roles = ['ROLE_ADMIN'];
        $em->flush();

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/projects');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.lp-sidebar__link[href="/admin"]');
    }

    public function test_login_page_does_not_show_nav(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.lp-sidebar');
    }
}
