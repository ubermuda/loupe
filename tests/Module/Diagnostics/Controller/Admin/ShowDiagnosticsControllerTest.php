<?php

declare(strict_types=1);

namespace App\Tests\Module\Diagnostics\Controller\Admin;

use App\Module\Account\Diagnostics\AgentAccountCheck;
use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Ubermuda\HealthCheckBundle\Command\RunDiagnosticsHandler;
use Ubermuda\HealthCheckBundle\Testing\HealthChecks;

final class ShowDiagnosticsControllerTest extends WebTestCase
{
    public function test_admin_sees_the_checks(): void
    {
        $client = static::createClient();
        $this->useOfflineStatusHandler();
        $admin = $this->seedUser('system-status-admin@admin-test.example.com', ['ROLE_ADMIN']);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/status');

        self::assertResponseIsSuccessful();
        self::assertSame('System status — Loupe', $crawler->filter('title')->text());
        self::assertGreaterThan(0, $crawler->filter('[data-system-check="mailer"]')->count());
        self::assertGreaterThan(0, $crawler->filter('[data-system-check="failed_messages"]')->count());
        // A bundle check resolves its label and its state name in the bundle's
        // catalogue.
        self::assertSame(
            'Mail transport',
            $crawler->filter('[data-system-check="mailer"] .status-check-label')->text(),
        );
        self::assertStringContainsString(
            'Failed',
            $crawler->filter('[data-system-check="mailer"] .status-check-badge')->text(),
        );
        // The failed transport is otherwise undiscoverable, so the page names
        // the commands that inspect and re-queue it.
        self::assertStringContainsString('messenger:failed:show', $crawler->filter('body')->text());
        self::assertStringContainsString('messenger:failed:retry', $crawler->filter('body')->text());
        // Sidebar entry, so an operator can find the page without knowing the URL.
        self::assertGreaterThan(0, $crawler->filter('a[href="/admin/status"]')->count());
    }

    /**
     * An application's own check keeps its keys in the application catalogue,
     * so the page has to resolve two domains at once.
     */
    public function test_an_application_check_is_labelled_from_the_application_catalogue(): void
    {
        $client = static::createClient();
        $admin = $this->seedUser('system-status-domains@admin-test.example.com', ['ROLE_ADMIN']);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::getContainer()->set(
            RunDiagnosticsHandler::class,
            new RunDiagnosticsHandler([new AgentAccountCheck($connection, new NullLogger())]),
        );

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/admin/status');

        self::assertResponseIsSuccessful();
        // Guard: the replacement carries this one check and no other, so a
        // container that ignored the swap would still show the mailer row.
        self::assertSame(0, $crawler->filter('[data-system-check="mailer"]')->count());
        self::assertSame(
            'Agent account',
            $crawler->filter('[data-system-check="agent_account"] .status-check-label')->text(),
        );
    }

    public function test_logged_in_non_admin_gets_403(): void
    {
        $client = static::createClient();
        $user = $this->seedUser('system-status-user@admin-test.example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/admin/status');

        self::assertResponseStatusCodeSame(403);
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/admin/status');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    /**
     * The container's handler opens an SMTP connection and calls the Mercure
     * hub; swap in one wired to a null mail transport and no hub so the test
     * touches no network.
     */
    private function useOfflineStatusHandler(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::getContainer()->set(
            RunDiagnosticsHandler::class,
            HealthChecks::handler($connection),
        );
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User(fullName: 'Test User', email: $email);
        AcceptedTerms::stamp($user, static::getContainer());
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
