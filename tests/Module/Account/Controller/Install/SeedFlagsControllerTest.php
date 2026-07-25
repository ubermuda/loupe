<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller\Install;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Ubermuda\FeatureFlagsBundle\Repository\FeatureFlagRepository;

final class SeedFlagsControllerTest extends WebTestCase
{
    public function test_renders_prefilled_form_on_empty_database(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install');

        self::assertResponseIsSuccessful();
        self::assertSame('14', $client->getCrawler()->filter('input[name="install_flags_form[billingTrialDays]"]')->attr('value'));
    }

    public function test_returns_404_once_a_user_exists(): void
    {
        $client = static::createClient();
        $this->createUser($client);

        $client->request('GET', '/install');

        self::assertResponseStatusCodeSame(404);
    }

    public function test_valid_submit_seeds_flags_and_redirects_to_step_two(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install');
        $client->submitForm('Continue', [
            'install_flags_form[registrationCap]' => 25,
            'install_flags_form[billingTrialDays]' => 30,
        ]);

        self::assertResponseRedirects('/install/admin');
        $flags = self::getContainer()->get(FeatureFlagRepository::class)->findAllIndexed();
        self::assertSame(25, $flags['registration.cap']->value);
        self::assertSame(30, $flags['billing.trial_days']->value);
        self::assertCount(6, $flags);
    }

    public function test_invalid_submit_returns_422(): void
    {
        $client = static::createClient();
        $client->request('GET', '/install');
        $client->submitForm('Continue', [
            'install_flags_form[billingTrialDays]' => -3,
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    private function createUser(KernelBrowser $client): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User(username: 'existing', fullName: 'Existing User', email: 'existing@example.com');
        $user->password = 'irrelevant-hash';
        $em->persist($user);
        $em->flush();
    }
}
