<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Success path of the resend flow. The 403 invalid-CSRF path is covered by
 * ValidateCsrfTokenListenerTest; the throttled path by the when@test limiter
 * override being the only thing between this test and a 429-style flash.
 */
final class ResendVerificationEmailControllerTest extends WebTestCase
{
    public function test_resend_sends_a_verification_email_for_unverified_user(): void
    {
        $client = static::createClient();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('resenduser', 'Resend User', 'resend@example.com');
        $user->password = $hasher->hashPassword($user, 'SecurePassword1!');
        $em->persist($user);
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/register/check-email');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Resend verification email');

        $this->assertResponseRedirects('/register/check-email');
        $this->assertQueuedEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'resend@example.com');
    }
}
