<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestAccountDeletionControllerTest extends WebTestCase
{
    public function test_post_sends_email_and_redirects(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Del Ctrl', 'del-ctrl@example.com', 'hash');
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();
        $em->clear();

        $client->loginUser($user);
        // Establishes the origin cookie the stateless CSRF sentinel needs.
        $client->request(Request::METHOD_GET, '/account');
        $client->request(Request::METHOD_POST, '/account/delete/request', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/account');
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'del-ctrl@example.com');
    }

    public function test_anonymous_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/account/delete/request', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }
}
