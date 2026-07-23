<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class VerifyEmailControllerTest extends WebTestCase
{
    /** @return array{User, string} the unverified user and their plain verification token */
    private function createUnverifiedUserWithToken(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('verifyuser', 'Verify User', 'verify@example.com');
        $user->password = $hasher->hashPassword($user, 'SecurePassword1!');
        $token = $user->generateEmailVerificationToken();

        $em->persist($user);
        $em->flush();
        $em->clear();

        return [$user, $token];
    }

    public function test_valid_token_verifies_and_logs_in(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->createUnverifiedUserWithToken();

        $client->request(Request::METHOD_GET, '/register/verify?token='.$token);

        $this->assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fetched = $em->find(User::class, $user->id);
        $this->assertNotNull($fetched);
        $this->assertTrue($fetched->isVerified());
        // The token must be single-use: cleared on successful verification.
        $this->assertFalse($fetched->isEmailVerificationTokenValid($token));
    }

    public function test_invalid_token_redirects_to_check_email_and_does_not_verify(): void
    {
        $client = static::createClient();
        [$user] = $this->createUnverifiedUserWithToken();

        $client->request(Request::METHOD_GET, '/register/verify?token=not-the-token');

        $this->assertResponseRedirects('/register/check-email');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $fetched = $em->find(User::class, $user->id);
        $this->assertNotNull($fetched);
        $this->assertFalse($fetched->isVerified());
    }

    public function test_missing_token_redirects_to_check_email(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/register/verify');

        $this->assertResponseRedirects('/register/check-email');
    }
}
