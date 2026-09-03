<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Controller\JoinWaitlistController;
use App\Module\Account\Controller\RegisterController;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Audit\AuditOutcome;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\InstalledInstance;
use App\Tests\Support\RecordingAuditor;
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

    /**
     * A visitor asked to sign up and a policy said no. The request path is the
     * only other thing the branch knows and it is caller-controlled, so the
     * record carries the refusal and nothing else.
     */
    public function test_a_closed_instance_records_both_refusals_with_no_context(): void
    {
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, false);

        $audit = RecordingAuditor::installedIn(static::getContainer());
        $client->disableReboot();

        $client->request(Request::METHOD_GET, '/register');
        $client->request(Request::METHOD_GET, '/waitlist');

        self::assertSame(
            ['account.registration_denied', 'account.waitlist_denied'],
            $audit->operations(),
        );

        foreach (['account.registration_denied', 'account.waitlist_denied'] as $operation) {
            $record = $audit->record($operation);
            self::assertSame(AuditOutcome::Refused, $record->outcome);
            self::assertSame([], $record->context);
            self::assertNull($record->subject);
        }
    }

    public function test_the_gated_controllers_keep_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(RegisterController::class);
        DirectLogging::assertRemovedFrom(JoinWaitlistController::class);
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

    public function test_the_login_page_hides_its_sign_up_links_when_registration_is_closed(): void
    {
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, false);

        $client->request(Request::METHOD_GET, '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/register"]');
    }

    public function test_the_login_page_still_offers_sign_up_when_registration_is_open(): void
    {
        // Without this the assertion above would pass on a login page that
        // never had the links in the first place.
        $client = static::createClient();
        $this->install($client);
        $this->setRegistrationEnabled($client, true);

        $client->request(Request::METHOD_GET, '/login');

        self::assertSelectorExists('a[href="/register"]');
    }

    private function install(KernelBrowser $client): void
    {
        InstalledInstance::ensure($client->getContainer());
    }

    private function setRegistrationEnabled(KernelBrowser $client, bool $enabled): void
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new FeatureFlag(name: RegistrationGate::ENABLED_FLAG, type: FeatureFlagType::Bool, value: $enabled));
        $em->flush();
    }
}
