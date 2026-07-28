<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

/**
 * The two conditions that close self-service sign-up regardless of the
 * registration cap: the master switch, and an install that has not run.
 *
 * The second one is the one with teeth. A fresh internet-facing deploy with
 * INSTALL_TOKEN unset 404s /install, and before this gate existed /register was
 * still wide open — so the first passer-by to register closed the wizard
 * forever, leaving the instance with no administrator and no seeded flags.
 */
final class RegistrationGatingTest extends WebTestCase
{
    public function test_register_404s_while_the_install_wizard_is_still_open(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/register');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_waitlist_404s_while_the_install_wizard_is_still_open(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/waitlist');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_register_404s_when_the_master_switch_is_off(): void
    {
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, false);

        $client->request(Request::METHOD_GET, '/register');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_waitlist_404s_when_the_master_switch_is_off(): void
    {
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, false);

        $client->request(Request::METHOD_GET, '/waitlist');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function test_register_is_reachable_once_installed_with_the_switch_on(): void
    {
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, true);

        $client->request(Request::METHOD_GET, '/register');

        self::assertResponseIsSuccessful();
    }

    /**
     * Guards the default in RegistrationGate::allowsNewAccounts(): an instance
     * upgraded from a version that never seeded the flag has no row for it, and
     * must keep accepting registrations exactly as it did before.
     */
    public function test_a_missing_flag_row_leaves_registration_open(): void
    {
        $client = static::createClient();
        $this->install($client);

        $client->request(Request::METHOD_GET, '/register');

        self::assertResponseIsSuccessful();
    }

    private function install(KernelBrowser $client): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        InstalledInstance::ensure($em);
    }

    private function setRegistrationEnabled(KernelBrowser $client, bool $enabled): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: $enabled));
        $em->flush();
    }
}
