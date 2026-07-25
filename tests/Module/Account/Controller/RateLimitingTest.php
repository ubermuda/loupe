<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Ubermuda\FeatureFlagsBundle\Entity\FeatureFlag;
use Ubermuda\FeatureFlagsBundle\Enum\FeatureFlagType;

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

    public function test_waitlist_join_is_rate_limited_per_ip(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the limiter override alive across requests
        static::getContainer()->set('limiter.waitlist_join', self::lowLimitFactory('waitlist_join'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new User(username: 'waitlist-limit-gate-filler', fullName: 'Gate Filler', email: 'waitlist-limit-gate-filler@example.com', password: 'x'));
        $em->flush();
        $userCount = static::getContainer()->get(UserRepository::class)->countAll();
        $em->persist(new FeatureFlag(name: 'registration.cap', type: FeatureFlagType::Int, value: $userCount));
        $em->flush();

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'first-waiter@example.com',
        ]);
        $this->assertResponseRedirects('/waitlist?joined=1');

        $client->request(Request::METHOD_GET, '/waitlist');
        $client->submitForm('Join the waitlist', [
            'waitlist_join_form[email]' => 'second-waiter@example.com',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertAnySelectorTextContains('.field-errors', 'Too many attempts');
    }
}
