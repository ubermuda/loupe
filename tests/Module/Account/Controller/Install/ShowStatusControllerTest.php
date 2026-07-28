<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Command\CheckSystemStatusHandler;
use App\Module\Account\Entity\User;
use App\Tests\Support\SystemStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ShowStatusControllerTest extends WebTestCase
{
    public function test_redirects_to_step_one_without_session_marker(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/install/status');

        self::assertResponseRedirects('/install');
    }

    public function test_renders_the_checks_after_step_one(): void
    {
        $client = static::createClient();
        $this->useOfflineStatusHandler($client);
        $this->completeStepOne($client);

        $crawler = $client->request(Request::METHOD_GET, '/install/status');

        self::assertResponseIsSuccessful();
        // Stable hooks rather than markup structure: one row per check, each
        // carrying its own state.
        self::assertGreaterThan(0, $crawler->filter('[data-system-check="mailer"]')->count());
        self::assertSame(
            'failed',
            $crawler->filter('[data-system-check="mailer"]')->attr('data-system-check-state'),
        );
        self::assertGreaterThan(0, $crawler->filter('[data-system-check="worker"]')->count());
        // The way forward is still offered even when a check failed — an
        // operator may knowingly run without mail.
        self::assertGreaterThan(0, $crawler->filter('a[href="/install/admin"]')->count());
    }

    public function test_404s_once_a_user_exists(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'existing', fullName: 'Existing User', email: 'existing@example.com');
        $user->password = 'irrelevant-hash';
        $em->persist($user);
        $em->flush();

        $client->request(Request::METHOD_GET, '/install/status');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The container's handler opens an SMTP connection and calls the Mercure
     * hub; swap in one wired to a null mail transport and no hub so the test
     * touches no network and its assertions are deterministic.
     */
    private function useOfflineStatusHandler(KernelBrowser $client): void
    {
        $client->disableReboot();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::getContainer()->set(
            CheckSystemStatusHandler::class,
            SystemStatus::handler($connection),
        );
    }

    private function completeStepOne(KernelBrowser $client): void
    {
        $client->request(Request::METHOD_GET, '/install');
        $client->submitForm('Continue', []);
    }
}
