<?php

namespace App\Tests\Module\Account\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitingTest extends WebTestCase
{
    private static function lowLimitFactory(string $id): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the limiter override alive across requests
        static::getContainer()->set('limiter.registration', self::lowLimitFactory('registration'));

        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'First User',
            'registration_form[username]' => 'firstuser',
            'registration_form[email]' => 'first@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertResponseRedirects('/register/check-email');

        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[fullName]' => 'Second User',
            'registration_form[username]' => 'seconduser',
            'registration_form[email]' => 'second@example.com',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('.field-errors', 'Too many registration attempts');
    }

    public function test_password_reset_request_is_rate_limited_per_ip(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.password_reset_request', self::lowLimitFactory('password_reset_request'));

        $client->request(Request::METHOD_GET, '/forgot-password');
        $client->submitForm('Send reset link', [
            'reset_password_request_form[email]' => 'whoever@example.com',
        ]);
        $this->assertResponseRedirects('/forgot-password/check-email');

        $client->request(Request::METHOD_GET, '/forgot-password');
        $client->submitForm('Send reset link', [
            'reset_password_request_form[email]' => 'whoever@example.com',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('.field-errors', 'Too many password reset requests');
    }
}
