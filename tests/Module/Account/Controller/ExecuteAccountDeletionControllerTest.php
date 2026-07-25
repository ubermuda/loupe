<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ExecuteAccountDeletionControllerTest extends WebTestCase
{
    public function test_confirm_post_deletes_account_and_logs_out(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('del-exec', 'Del Exec', 'del-exec@example.com', 'hash');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $token = $user->generateAccountDeletionToken();
        $em->flush();
        $userId = $user->id;

        $client->loginUser($user);
        // Establishes the origin cookie the stateless CSRF sentinel needs.
        $client->request(Request::METHOD_GET, '/account/delete/confirm?token='.$token);
        $client->request(Request::METHOD_POST, '/account/delete/confirm', [
            'token' => $token,
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects('/goodbye');

        $em->clear();
        self::assertNull($em->find(User::class, $userId));

        // The old session must no longer authenticate.
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_remember_me_cookie_no_longer_authenticates_after_deletion(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        $user = new User('del-rm', 'Del RM', 'del-rm@example.com');
        $user->password = $hasher->hashPassword($user, 'p4ssw0rd!');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        // Real form login with remember-me so the jar holds the configured
        // persistent cookie.
        $client->request(Request::METHOD_GET, '/login');
        $client->submitForm('Sign in', [
            'email' => 'del-rm@example.com',
            'password' => 'p4ssw0rd!',
            '_remember_me' => true,
        ]);

        $cookieJar = $client->getCookieJar();
        $rememberMeCookie = null;
        foreach ($cookieJar->all() as $cookie) {
            if (!str_contains(strtolower($cookie->getName()), 'sess')) {
                $rememberMeCookie = $cookie;
            }
        }
        self::assertNotNull($rememberMeCookie, 'login with _remember_me must set a persistent cookie');

        $em->clear();
        $fresh = $em->find(User::class, $user->id);
        self::assertNotNull($fresh);
        $token = $fresh->generateAccountDeletionToken();
        $em->flush();

        $client->request(Request::METHOD_POST, '/account/delete/confirm', [
            'token' => $token,
            '_csrf_token' => 'csrf-token',
        ]);
        self::assertResponseRedirects('/goodbye');

        // Simulate a new browser visit that presents ONLY the remember-me
        // cookie: drop the session cookie, keep the remember-me one.
        foreach ($cookieJar->all() as $cookie) {
            if (str_contains(strtolower($cookie->getName()), 'sess')) {
                $cookieJar->expire($cookie->getName());
            }
        }
        $client->request(Request::METHOD_GET, '/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function test_invalid_token_does_not_delete(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/account/delete/confirm', [
            'token' => 'bogus',
            '_csrf_token' => 'csrf-token',
        ]);

        self::assertResponseRedirects();
    }

    public function test_goodbye_is_public(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/goodbye');

        self::assertResponseIsSuccessful();
    }
}
