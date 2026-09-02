<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Controller\Install\CreateAdminController;
use App\Module\Account\Repository\UserRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use App\Tests\Support\DirectLogging;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateAdminControllerTest extends WebTestCase
{
    public function test_redirects_to_step_one_without_session_marker(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');

        self::assertResponseRedirects('/install');
    }

    public function test_renders_after_step_one(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_full_flow_creates_unverified_admin_and_closes_wizard(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[fullName]' => 'Ada Lovelace',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        self::assertResponseRedirects('/install/done');
        $this->assertQueuedEmailCount(1);

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('admin@example.com');
        self::assertNotNull($user);
        self::assertSame(['ROLE_ADMIN'], $user->roles);
        self::assertSame('Ada Lovelace', $user->fullName);
        self::assertNull($user->emailVerifiedAt);

        // Wizard is now closed…
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');
        self::assertResponseStatusCodeSame(404);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');
        self::assertResponseStatusCodeSame(404);
        // …while this session still renders the done page once (completion marker).
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/done');
        self::assertResponseIsSuccessful();
        // The completion marker is consumed by that first render — a second
        // visit (e.g. a page refresh) 404s just like every other install route.
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/done');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_creating_the_first_admin_is_recorded(): void
    {
        $client = static::createClient();
        $audit = RecordingAuditor::installedIn(static::getContainer());
        $client->disableReboot();
        $this->completeStepOne($client);
        $audit->forget();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[email]' => 'admin-audit@example.com',
            'install_admin_form[fullName]' => 'Ada Lovelace',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        self::assertResponseRedirects('/install/done');

        $admin = self::getContainer()->get(UserRepository::class)->findOneByEmail('admin-audit@example.com');
        self::assertNotNull($admin);

        $record = $audit->record('account.install.admin_created');
        self::assertSame(AuditOutcome::Success, $record->outcome);
        self::assertSame(Auditor::CATEGORY_DOMAIN, $record->category);
        self::assertSame([], $record->context);
        self::assertInstanceOf(AuditSubject::class, $record->subject);
        self::assertSame('user', $record->subject->type);
        self::assertSame((string) $admin->id, $record->subject->id);

        self::assertSame(['account.install.admin_created'], $audit->domainLogLines());
        self::assertSame([], $audit->securityLogLines());
    }

    public function test_the_controller_keeps_no_logger_beside_the_auditor(): void
    {
        DirectLogging::assertRemovedFrom(CreateAdminController::class);
    }

    public function test_dto_validation_failure_returns_422(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[fullName]' => 'Ada Lovelace',
            // Length(min: 8) violation.
            'install_admin_form[plainPassword]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // The controller's DomainErrors catch needs two concurrent requests racing
    // the handler's advisory lock, which dama's single-transaction wrapper cannot
    // express. The handler's own sequential second-call test guards the
    // thrown-error contract.

    public function test_a_blank_display_name_is_a_validation_error(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');

        $client->submitForm('Create admin account', [
            'install_admin_form[email]' => 'nameless@example.com',
            'install_admin_form[fullName]' => '',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('nameless@example.com'));
    }

    public function test_post_without_marker_redirects_and_creates_nothing(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/install/admin', [
            'install_admin_form' => ['email' => 'x@example.com', 'fullName' => 'Ada Lovelace', 'plainPassword' => 'a-strong-password'],
        ]);

        self::assertResponseRedirects('/install');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('x@example.com'));
    }

    public function test_all_wizard_routes_404_once_a_user_exists_even_for_post(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[fullName]' => 'Ada Lovelace',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        foreach ([['GET', '/install'], ['POST', '/install'], ['GET', '/install/status'], ['GET', '/install/admin'], ['POST', '/install/admin']] as [$method, $path]) {
            $client->request($method, $path);
            self::assertResponseStatusCodeSame(404, sprintf('%s %s must 404 once installed', $method, $path));
        }
    }

    public function test_done_page_404s_without_completion_marker(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install/done');

        self::assertResponseStatusCodeSame(404);
    }

    private function completeStepOne(KernelBrowser $client): void
    {
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/install');
        $client->submitForm('Continue', []);
    }
}
