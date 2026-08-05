<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConfirmAccountDeletionControllerTest extends WebTestCase
{
    public function test_valid_token_shows_confirmation_form(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Del Confirm', 'del-confirm@example.com', 'hash');
        $em->persist($user);
        $token = $user->generateAccountDeletionToken();
        $em->flush();
        $userId = $user->id;

        $crawler = $client->request(Request::METHOD_GET, '/account/delete/confirm?token='.$token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('del-confirm@example.com', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('form[action$="/account/delete/confirm"]'));

        // GET must not mutate: the token is still valid afterwards.
        $em->clear();
        $fresh = $em->find(User::class, $userId);
        self::assertNotNull($fresh);
        self::assertTrue($fresh->isAccountDeletionTokenValid($token));
    }

    public function test_unknown_token_shows_invalid_page(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account/delete/confirm?token=deadbeef');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no longer valid', (string) $client->getResponse()->getContent());
    }

    public function test_expired_token_shows_invalid_page(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('Del Expired', 'del-expired@example.com', 'hash');
        $em->persist($user);
        $token = $user->generateAccountDeletionToken();
        $em->flush();

        $ref = new \ReflectionProperty(User::class, 'accountDeletionTokenExpiresAt');
        $ref->setValue($user, new \DateTimeImmutable('-1 minute'));
        $em->flush();

        $client->request(Request::METHOD_GET, '/account/delete/confirm?token='.$token);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no longer valid', (string) $client->getResponse()->getContent());
    }

    public function test_missing_token_shows_invalid_page(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_GET, '/account/delete/confirm');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no longer valid', (string) $client->getResponse()->getContent());
    }
}
