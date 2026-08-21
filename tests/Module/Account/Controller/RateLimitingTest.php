<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Service\RegistrationGate;
use App\Tests\Support\InstalledInstance;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));

        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[email]' => 'first@example.com',
            'registration_form[fullName]' => 'Riley Chen',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ]);
        $this->assertResponseRedirects('/register/check-email');

        $client->request(Request::METHOD_GET, '/register');
        $client->submitForm('Create account', [
            'registration_form[email]' => 'second@example.com',
            'registration_form[fullName]' => 'Riley Chen',
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

    public function test_password_reset_request_is_rate_limited_per_address_across_rotating_ips(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.password_reset_request_address', self::lowLimitFactory('password_reset_request_address'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user = new User(fullName: 'Flood Victim', email: 'victim@example.com', password: 'hash'));
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->flush();

        $accepted = $this->submitPasswordReset($client, 'victim@example.com', '203.0.113.1');
        $this->assertCount(1, self::getMailerMessages());

        // Cleared so the handler would happily send again: with the token still
        // active it returns early, and the assertions below could not tell a
        // throttled request apart from one the handler simply ignored.
        $user = $this->reloadVictim();
        $user->clearPasswordResetToken();
        $em->flush();

        $rejected = $this->submitPasswordReset($client, 'ViCtIm@Example.COM', '198.51.100.7');
        $this->assertCount(0, self::getMailerMessages(), 'The throttled request must not have produced a mail.');
        $this->assertFalse($this->reloadVictim()->hasActivePasswordResetToken(), 'The throttled request must not have reached the handler.');

        $this->assertSame(Response::HTTP_FOUND, $accepted->getStatusCode(), (string) $accepted->getContent());
        $this->assertSame(Response::HTTP_FOUND, $rejected->getStatusCode(), (string) $rejected->getContent());
        $this->assertSame(
            $accepted->headers->get('Location'),
            $rejected->headers->get('Location'),
            'A throttled reset must be indistinguishable from an accepted one.',
        );
        $this->assertSame($accepted->getContent(), $rejected->getContent());
    }

    public function test_registration_is_rate_limited_per_address_across_rotating_ips(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.registration_address', self::lowLimitFactory('registration_address'));
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));

        $client->request(Request::METHOD_GET, '/register', server: ['REMOTE_ADDR' => '203.0.113.2']);
        $client->submitForm('Create account', [
            'registration_form[email]' => 'flooded@example.com',
            'registration_form[fullName]' => 'Riley Chen',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ], serverParameters: ['REMOTE_ADDR' => '203.0.113.2']);
        $this->assertResponseRedirects('/register/check-email');

        $client->request(Request::METHOD_GET, '/register', server: ['REMOTE_ADDR' => '198.51.100.8']);
        $client->submitForm('Create account', [
            'registration_form[email]' => 'Flooded@Example.com',
            'registration_form[fullName]' => 'Riley Chen',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ], serverParameters: ['REMOTE_ADDR' => '198.51.100.8']);

        // Reported rather than success-shaped. A fake check-email redirect here
        // would stash an address the caller does not own into the session that
        // the resend flow trusts, which is a way to mail a stranger.
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertCount(
            1,
            static::getContainer()->get(UserRepository::class)->findBy(['email' => 'flooded@example.com']),
        );
    }

    public function test_resend_verification_is_rate_limited_per_address_across_rotating_ips(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.resend_verification_email_address', self::lowLimitFactory('resend_verification_email_address'));
        InstalledInstance::ensure(static::getContainer()->get(EntityManagerInterface::class));

        $client->request(Request::METHOD_GET, '/register', server: ['REMOTE_ADDR' => '203.0.113.3']);
        $client->submitForm('Create account', [
            'registration_form[email]' => 'resend-flood@example.com',
            'registration_form[fullName]' => 'Riley Chen',
            'registration_form[plainPassword]' => 'SecurePassword1!',
            'registration_form[agreeTerms]' => true,
        ], serverParameters: ['REMOTE_ADDR' => '203.0.113.3']);
        $client->followRedirect();

        $this->submitResend($client, '203.0.113.3');
        $this->assertResponseRedirects('/register/check-email');
        $client->followRedirect();
        $this->assertAnySelectorTextContains('body', 'Verification email resent.');

        $this->submitResend($client, '198.51.100.9');
        $client->followRedirect();
        $this->assertAnySelectorTextContains('body', 'Please wait before requesting another verification email.');
    }

    private function submitResend(KernelBrowser $client, string $ip): void
    {
        $client->submitForm('Resend verification email', [], serverParameters: ['REMOTE_ADDR' => $ip]);
    }

    private function reloadVictim(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail('victim@example.com');
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    private function submitPasswordReset(KernelBrowser $client, string $email, string $ip): Response
    {
        $client->request(Request::METHOD_GET, '/forgot-password', server: ['REMOTE_ADDR' => $ip]);
        $client->submitForm('Send reset link', [
            'reset_password_request_form[email]' => $email,
        ], serverParameters: ['REMOTE_ADDR' => $ip]);

        return $client->getResponse();
    }

    public function test_waitlist_join_is_rate_limited_per_ip(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // keep the limiter override alive across requests
        static::getContainer()->set('limiter.waitlist_join', self::lowLimitFactory('waitlist_join'));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new User(fullName: 'Gate Filler', email: 'waitlist-limit-gate-filler@example.com', password: 'x'));
        $em->flush();
        $userCount = static::getContainer()->get(UserRepository::class)->countActive();
        $em->persist(new FeatureFlag(name: RegistrationGate::CAP_FLAG, type: FeatureFlagType::Int, value: $userCount));
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
