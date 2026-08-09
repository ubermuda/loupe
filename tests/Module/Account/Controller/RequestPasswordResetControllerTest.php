<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestPasswordResetControllerTest extends WebTestCase
{
    /**
     * Regression for the reset link following a forged Host: the app trusts
     * X-Forwarded-Host from the loopback proxy hop (trusted_proxies:
     * PRIVATE_SUBNETS, which the WebTestCase client's 127.0.0.1 REMOTE_ADDR
     * satisfies), so PasswordResetEmailSender must pin the link to
     * DEFAULT_URI rather than the live request context.
     */
    public function test_reset_link_ignores_a_forged_forwarded_host(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Reset Forge', 'reset-forge@example.com', 'hash');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $client->request(Request::METHOD_GET, '/forgot-password');
        $client->submitForm(
            'Send reset link',
            ['reset_password_request_form[email]' => 'reset-forge@example.com'],
            'POST',
            // Origin is forged to match X-Forwarded-Host, as an attacker calling
            // the app directly would: SameOriginCsrfTokenManager compares the
            // forged Host against Origin, so leaving the real host would fail
            // CSRF instead of exercising the host pinning this test targets.
            ['HTTP_X_FORWARDED_HOST' => 'evil.example.com', 'HTTP_ORIGIN' => 'http://evil.example.com'],
        );

        self::assertResponseRedirects('/forgot-password/check-email');
        self::assertQueuedEmailCount(1);

        // By the time the queued-mail logger observes it, the TemplatedEmail
        // has already been rendered (markAsRendered() clears getContext()),
        // so the link is asserted against the rendered HTML body.
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'http://localhost/forgot-password/reset/');
        self::assertEmailHtmlBodyNotContains($email, 'evil.example.com');
    }
}
