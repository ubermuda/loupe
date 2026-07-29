<?php

declare(strict_types=1);

namespace App\Tests\Module\Admin\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ShowAdminDashboardControllerTest extends WebTestCase
{
    public function test_admin_gets_200(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'admin-dashboard@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseIsSuccessful();
        // The title composes the translated page part and the brand.
        $this->assertSame('Admin — Loupe', $crawler->filter('title')->text());
        $this->assertStringContainsString('Dashboard', $crawler->filter('body')->text());
        // Sidebar nav registry: our menu items plus the bundle's auto-registered flags item.
        $this->assertGreaterThan(0, $crawler->filter('a[href="/admin"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('a[href="/admin/feature-flags"]')->count());
        $this->assertGreaterThan(0, $crawler->filter('a[href="/admin/waitlist"]')->count());
        // The admin area renders the admin bundle's layout, not the app's, so it
        // is the one place the AGPL source offer can silently go missing.
        $sourceUrl = static::getContainer()->getParameter('app.source_url');
        $this->assertIsString($sourceUrl);
        $this->assertGreaterThan(0, $crawler->filter('a[href="'.$sourceUrl.'"]')->count());
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->seedUser($em, 'plain-user@admin-test.example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseRedirects();
        $this->assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(EntityManagerInterface $em, string $email, array $roles = []): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(username: 'u'.bin2hex(random_bytes(4)), fullName: 'Test User', email: $email);
        $user->password = $hasher->hashPassword($user, 'TestPass123!');
        // Unverified users are bounced by RedirectUnverifiedUserListener, which
        // would turn the expected 200/403 into a redirect.
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $user->roles = $roles;
        $em->persist($user);
        $em->flush();
        $em->clear();

        return $em->find(User::class, $user->id) ?? throw new \LogicException('User not found after clear');
    }
}
