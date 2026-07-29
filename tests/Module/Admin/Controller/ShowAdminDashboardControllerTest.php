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

    /**
     * Rendering the system status page opens an SMTP connection and probes the
     * Mercure hub, so its sidebar entry opts out of Turbo's hover prefetch by
     * implementing NonPrefetchableAdminMenuItem. The opt-out lives in the admin
     * bundle's layout, which no test other than this one renders — asserting the
     * marker interface is present would not prove the attribute reaches the page.
     */
    public function test_only_the_system_status_sidebar_link_opts_out_of_turbo_prefetch(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->seedUser($em, 'prefetch-sidebar@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin');

        $this->assertResponseIsSuccessful();

        $optedOut = $crawler->filter('a.admin-nav-link[data-turbo-prefetch="false"]');
        // Exactly one, so a layout that stamped the attribute on every nav link
        // — which would disable prefetching wholesale — fails here too.
        $this->assertSame(1, $optedOut->count());
        $this->assertSame('/admin/status', $optedOut->attr('href'));

        // The cheap list pages keep their prefetch; they are plain paginated reads.
        $this->assertGreaterThan(0, $crawler->filter('a.admin-nav-link[href="/admin/waitlist"]')->count());
        $this->assertSame(0, $crawler->filter('a.admin-nav-link[href="/admin/waitlist"][data-turbo-prefetch]')->count());
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
