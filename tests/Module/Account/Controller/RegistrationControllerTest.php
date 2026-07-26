<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function test_registration_page_loads(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $this->assertResponseIsSuccessful();
    }

    public function test_successful_registration_creates_user(): void
    {
        $client = static::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');

        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Test User',
            'registration_form[username]' => 'testuser',
            'registration_form[email]' => 'test@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseRedirects('/register/check-email');

        $container = static::getContainer();
        $user = $container->get(UserRepository::class)->findOneBy(['email' => 'test@example.com']);
        $this->assertNotNull($user);
        $this->assertSame('testuser', $user->username);

        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'test@example.com');
    }

    public function test_duplicate_email_shows_error(): void
    {
        $client = static::createClient();
        // Register once
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Test User',
            'registration_form[username]' => 'firstuser',
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertResponseRedirects('/register/check-email');

        // Try again with the same email but a different username
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Another User',
            'registration_form[username]' => 'seconduser',
            'registration_form[email]' => 'duplicate@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422); // re-renders form with validation errors
        $this->assertSelectorTextContains('body', 'already an account');
    }

    public function test_duplicate_username_shows_error(): void
    {
        $client = static::createClient();
        // Register once
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Test User',
            'registration_form[username]' => 'takenuser',
            'registration_form[email]' => 'first@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertResponseRedirects('/register/check-email');

        // Try again with the same username but a different email
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Another User',
            'registration_form[username]' => 'takenuser',
            'registration_form[email]' => 'second@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422); // re-renders form with validation errors
        $this->assertSelectorTextContains('body', 'username is already taken');
    }
}
