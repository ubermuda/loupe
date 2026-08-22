<?php

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Tests\Support\AcceptedTerms;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class LoginControllerTest extends WebTestCase
{
    private function createUser(bool $verified): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User('Login User', 'login@example.com');
        AcceptedTerms::stamp($user, static::getContainer());
        $user->password = $hasher->hashPassword($user, 'SecurePassword1!');
        if ($verified) {
            $user->emailVerifiedAt = new \DateTimeImmutable();
        }

        $em->persist($user);
        $em->flush();
        $em->clear();

        return $user;
    }

    public function test_login_page_loads(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
        $this->assertSelectorExists('input[name="password"]');
    }

    public function test_valid_credentials_redirect_to_home(): void
    {
        $client = static::createClient();
        $this->createUser(verified: true);

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'login@example.com',
            'password' => 'SecurePassword1!',
        ]);

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        // The home route sends a freshly verified user with no projects into
        // the first-run wizard.
        $this->assertResponseRedirects('/welcome');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form[action="/logout"]');
    }

    public function test_wrong_password_shows_auth_error(): void
    {
        $client = static::createClient();
        $this->createUser(verified: true);

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'login@example.com',
            'password' => 'WrongPassword!',
        ]);

        $this->assertResponseRedirects('/login');
        $client->followRedirect();
        $this->assertSelectorExists('.auth-error');
    }

    public function test_unverified_user_is_redirected_to_check_email(): void
    {
        $client = static::createClient();
        $this->createUser(verified: false);

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'login@example.com',
            'password' => 'SecurePassword1!',
        ]);

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertResponseRedirects('/register/check-email');
    }

    public function test_successful_login_stamps_last_signed_in_at(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: true);
        self::assertNull($user->lastSignedInAt);

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'login@example.com',
            'password' => 'SecurePassword1!',
        ]);
        $this->assertResponseRedirects('/');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(User::class, $user->id)?->lastSignedInAt);
    }

    public function test_failed_login_leaves_last_signed_in_at_untouched(): void
    {
        $client = static::createClient();
        $user = $this->createUser(verified: true);

        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'login@example.com',
            'password' => 'WrongPassword!',
        ]);
        $this->assertResponseRedirects('/login');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(User::class, $user->id)?->lastSignedInAt);
    }
}
