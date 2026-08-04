<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Repository\UserRepository;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function test_registration_page_loads(): void
    {
        $client = static::createClient();
        // Sign-up refuses to create the *first* account on an instance — that is
        // the install wizard's job — and the test database starts empty.
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $this->assertResponseIsSuccessful();
    }

    public function test_successful_registration_creates_user(): void
    {
        $client = static::createClient();
        // Sign-up refuses to create the *first* account on an instance — that is
        // the install wizard's job — and the test database starts empty.
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');

        $client->submitForm('Create account', [
            'registration_form[email]' => 'test@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseRedirects('/register/check-email');

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'test@example.com']);
        $this->assertNotNull($user);
        // Registration asks for no name and nothing derives one, so the account
        // renders its address until its owner sets one on /account/profile.
        $this->assertNull($user->fullName);
        $this->assertSame('test@example.com', $user->displayName());

        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'test@example.com');
    }

    public function test_duplicate_email_shows_error(): void
    {
        $client = static::createClient();
        // Sign-up refuses to create the *first* account on an instance — that is
        // the install wizard's job — and the test database starts empty.
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));
        // Register once
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertResponseRedirects('/register/check-email');

        // Submit the same email a second time
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422); // re-renders form with validation errors
        $this->assertSelectorTextContains('body', 'already an account');
    }
}
