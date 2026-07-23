<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ResetPasswordControllerTest extends WebTestCase
{
    /** @return array{User, string} the user and their plain reset token */
    private function createUserWithResetToken(): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('resetuser', 'Reset User', 'reset@example.com');
        $user->password = $hasher->hashPassword($user, 'OldPassword1!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $token = $user->generatePasswordResetToken();

        $em->persist($user);
        $em->flush();
        $em->clear();

        return [$user, $token];
    }

    public function test_valid_token_allows_password_change(): void
    {
        $client = static::createClient();
        [$user, $token] = $this->createUserWithResetToken();

        $client->request(Request::METHOD_GET, '/forgot-password/reset/'.$token);
        $this->assertResponseRedirects('/forgot-password/reset');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        $client->submitForm('Reset password', [
            'change_password_form[plainPassword][first]' => 'NewPassword1!',
            'change_password_form[plainPassword][second]' => 'NewPassword1!',
        ]);

        $this->assertResponseRedirects('/login');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $em->clear();
        $fetched = $em->find(User::class, $user->id);
        $this->assertNotNull($fetched);
        $this->assertTrue($hasher->isPasswordValid($fetched, 'NewPassword1!'));
        $this->assertFalse($fetched->hasActivePasswordResetToken());
    }

    public function test_unknown_token_redirects_to_forgot_password(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/forgot-password/reset/not-a-real-token');
        $this->assertResponseRedirects('/forgot-password/reset');
        $client->followRedirect();

        $this->assertResponseRedirects('/forgot-password');
    }

    public function test_no_token_at_all_is_404(): void
    {
        $client = static::createClient();

        $client->request(Request::METHOD_GET, '/forgot-password/reset');

        $this->assertResponseStatusCodeSame(404);
    }
}
