<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Regression for the account-deletion confirm link following a forged Host —
 * see RequestPasswordResetControllerTest for the same regression on the
 * password-reset link and why Origin is forged alongside X-Forwarded-Host.
 */
final class RequestAccountDeletionForgedHostTest extends WebTestCase
{
    public function test_confirm_link_ignores_a_forged_forwarded_host(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Del Forge', 'del-forge@example.com', 'hash');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        // Establishes the origin cookie the stateless CSRF sentinel needs.
        $client->request(Request::METHOD_GET, '/account');
        $client->request(
            Request::METHOD_POST,
            '/account/delete/request',
            ['_csrf_token' => 'csrf-token'],
            [],
            ['HTTP_X_FORWARDED_HOST' => 'evil.example.com', 'HTTP_ORIGIN' => 'http://evil.example.com'],
        );

        self::assertResponseRedirects('/account');
        self::assertQueuedEmailCount(1);

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHtmlBodyContains($email, 'http://localhost/account/delete/confirm');
        self::assertEmailHtmlBodyNotContains($email, 'evil.example.com');
    }
}
