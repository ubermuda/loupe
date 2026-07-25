<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CreateAdminControllerTest extends WebTestCase
{
    public function test_redirects_to_step_one_without_session_marker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install/admin');

        self::assertResponseRedirects('/install');
    }

    public function test_renders_after_step_one(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request('GET', '/install/admin');

        self::assertResponseIsSuccessful();
    }

    public function test_full_flow_creates_unverified_admin_and_closes_wizard(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request('GET', '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[fullName]' => 'The Admin',
            'install_admin_form[username]' => 'admin',
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        self::assertResponseRedirects('/install/done');
        $this->assertEmailCount(1);

        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('admin@example.com');
        self::assertNotNull($user);
        self::assertSame(['ROLE_ADMIN'], $user->roles);
        self::assertNull($user->emailVerifiedAt);

        // Wizard is now closed…
        $client->request('GET', '/install');
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/install/admin');
        self::assertResponseStatusCodeSame(404);
        // …while this session still renders the done page once (completion marker).
        $client->request('GET', '/install/done');
        self::assertResponseIsSuccessful();
        // The completion marker is consumed by that first render — a second
        // visit (e.g. a page refresh) 404s just like every other install route.
        $client->request('GET', '/install/done');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_dto_validation_failure_returns_422(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request('GET', '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[fullName]' => 'The Admin',
            'install_admin_form[username]' => 'ab', // Length(min: 3) violation
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    // The controller's DomainErrors catch is reachable only when two concurrent
    // requests both pass the InstallationState guard and one loses the handler's
    // advisory lock — not expressible under dama's single-transaction wrapper.
    // The catch block mirrors the project-wide DomainErrors pattern and is
    // covered by review; the handler's own sequential second-call test guards
    // the thrown-error contract.

    public function test_post_without_marker_redirects_and_creates_nothing(): void
    {
        $client = static::createClient();
        $client->request('POST', '/install/admin', [
            'install_admin_form' => ['fullName' => 'X', 'username' => 'xxx', 'email' => 'x@example.com', 'plainPassword' => 'a-strong-password'],
        ]);

        self::assertResponseRedirects('/install');
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneByEmail('x@example.com'));
    }

    public function test_all_wizard_routes_404_once_a_user_exists_even_for_post(): void
    {
        $client = static::createClient();
        $this->completeStepOne($client);
        $client->request('GET', '/install/admin');
        $client->submitForm('Create admin account', [
            'install_admin_form[fullName]' => 'The Admin',
            'install_admin_form[username]' => 'admin',
            'install_admin_form[email]' => 'admin@example.com',
            'install_admin_form[plainPassword]' => 'a-strong-password',
        ]);

        foreach ([['GET', '/install'], ['POST', '/install'], ['GET', '/install/admin'], ['POST', '/install/admin']] as [$method, $path]) {
            $client->request($method, $path);
            self::assertResponseStatusCodeSame(404, sprintf('%s %s must 404 once installed', $method, $path));
        }
    }

    public function test_done_page_404s_without_completion_marker(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install/done');

        self::assertResponseStatusCodeSame(404);
    }

    private function completeStepOne(KernelBrowser $client): void
    {
        $client->request('GET', '/install');
        $client->submitForm('Continue', []);
    }
}
