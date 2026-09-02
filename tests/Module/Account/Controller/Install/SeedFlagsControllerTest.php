<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Controller\Install\SeedFlagsController;
use App\Module\Account\Entity\User;
use App\Module\Account\Service\RegistrationGate;
use App\Module\Analytics\Twig\AnalyticsScript;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Review\Mcp\DocumentHighlightTool;
use App\Service\UpdateCheck;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SeedFlagsControllerTest extends WebTestCase
{
    public function test_renders_prefilled_form_on_empty_database(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');

        self::assertResponseIsSuccessful();
        self::assertSame('14', $client->getCrawler()->filter('input[name="install_flags_form[billingTrialDays]"]')->attr('value'));
    }

    public function test_returns_404_once_a_user_exists(): void
    {
        $client = static::createClient();
        $this->createUser($client);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_valid_submit_seeds_flags_and_redirects_to_step_two(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');
        $client->submitForm('Continue', [
            'install_flags_form[registrationCap]' => 25,
            'install_flags_form[billingTrialDays]' => 30,
        ]);

        self::assertResponseRedirects('/install/status');
        $flags = self::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed();
        self::assertSame(25, $flags['registration.cap']->value);
        self::assertSame(30, $flags['billing.trial_days']->value);
        // Load-bearing: the submit above leaves the registration checkbox at its
        // prefilled default, and that default has to be "on" or a freshly
        // installed instance cannot register anybody.
        self::assertTrue($flags[RegistrationGate::ENABLED_FLAG]->value);
        self::assertCount(12, $flags);
        // Seeded off: the update check is the app's only self-initiated
        // outbound request, so an install must not start making it unasked.
        self::assertFalse($flags[UpdateCheck::FLAG]->value);
        self::assertFalse($flags[DocumentHighlightTool::FLAG]->value);
        // Same reasoning as the update check: an install sends nothing to a
        // third party until someone decides it should.
        self::assertFalse($flags[AnalyticsScript::ENABLED_FLAG]->value);
    }

    public function test_seeding_the_flags_is_recorded(): void
    {
        $client = static::createClient();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $client->disableReboot();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');
        $client->submitForm('Continue', [
            'install_flags_form[registrationCap]' => 25,
            'install_flags_form[billingTrialDays]' => 30,
        ]);

        self::assertResponseRedirects('/install/status');

        $record = $audit->record('account.install.flags_seeded');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame([], $record->context);
        self::assertNull($record->subject);

        self::assertSame(['account.install.flags_seeded'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(SeedFlagsController::class);
    }

    public function test_invalid_submit_returns_422(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');
        $client->submitForm('Continue', [
            'install_flags_form[billingTrialDays]' => -3,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    private function createUser(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(fullName: 'Existing User', email: 'existing@example.com');
        $user->password = 'irrelevant-hash';
        $em->persist($user);
        $em->flush();
    }
}
