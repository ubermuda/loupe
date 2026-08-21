<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Admin;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ListUsersControllerTest extends WebTestCase
{
    public function test_admin_gets_200(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'users-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Users');
        $this->assertSelectorTextNotContains('body', 'account.admin.users.');
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->seedUser($em, 'users-plain@admin-test.example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/users');

        $this->assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/admin/users');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_unknown_sort_falls_back_to_the_default(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'users-sort@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/admin/users?sort=not-a-real-column&dir=sideways');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $admin->id));
    }

    public function test_search_matches_name_and_email(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'users-search@admin-test.example.com', ['ROLE_ADMIN']);
        $needle = $this->seedUser($em, 'zaphod@admin-test.example.com', fullName: 'Zaphod Beeblebrox');

        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users?q=beeble');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $needle->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $admin->id));

        $client->request(Request::METHOD_GET, '/admin/users?q=ZAPHOD@ADMIN-TEST');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $needle->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $admin->id));
    }

    public function test_role_verification_and_state_filters_narrow_the_list(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'users-filters@admin-test.example.com', ['ROLE_ADMIN']);

        $unverified = $this->seedUser($em, 'users-unverified@admin-test.example.com');
        $unverified->emailVerifiedAt = null;
        $suspended = $this->seedUser($em, 'users-suspended@admin-test.example.com');
        $suspended->suspendedAt = new \DateTimeImmutable();
        $disabled = $this->seedUser($em, 'users-disabled@admin-test.example.com');
        $disabled->disabledAt = new \DateTimeImmutable();
        $em->flush();

        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/admin/users?role=admin');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $admin->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $suspended->id));

        $client->request(Request::METHOD_GET, '/admin/users?role=user');
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $admin->id));
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $suspended->id));

        $client->request(Request::METHOD_GET, '/admin/users?verified=no');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $unverified->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $admin->id));

        $client->request(Request::METHOD_GET, '/admin/users?verified=yes');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $admin->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $unverified->id));

        $client->request(Request::METHOD_GET, '/admin/users?state=suspended');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $suspended->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $disabled->id));

        $client->request(Request::METHOD_GET, '/admin/users?state=disabled');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $disabled->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $suspended->id));

        $client->request(Request::METHOD_GET, '/admin/users?state=active');
        $this->assertSelectorExists(sprintf('[data-user-id="%s"]', $admin->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $suspended->id));
        $this->assertSelectorNotExists(sprintf('[data-user-id="%s"]', $disabled->id));
    }

    public function test_the_agent_account_is_never_listed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'users-agent@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);

        // The bare list, plus every filter combination the agent row would
        // otherwise satisfy — the search terms are the agent's own name and
        // email, which is what catches an OR that binds looser than the
        // exclusion.
        $urls = [
            '/admin/users',
            '/admin/users?q=agent',
            '/admin/users?q=agent@loupe.invalid',
            '/admin/users?role=user',
            '/admin/users?verified=no',
            '/admin/users?state=active',
            '/admin/users?q=agent&role=user&verified=no&state=active',
        ];

        foreach ($urls as $url) {
            $crawler = $client->request(Request::METHOD_GET, $url);
            $this->assertResponseIsSuccessful($url);
            $this->assertCount(
                0,
                $crawler->filter(sprintf('[data-user-id="%s"]', User::AGENT_ID)),
                $url,
            );
        }
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = [], string $fullName = 'Test User'): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(fullName: $fullName, email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = $hasher->hashPassword($user, 'TestPass123!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
