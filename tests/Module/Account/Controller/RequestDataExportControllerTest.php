<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestDataExportControllerTest extends WebTestCase
{
    /** @param non-empty-string $email */
    private function createVerifiedUser(EntityManagerInterface $em, string $username, string $email): User
    {
        $user = new User(
            username: $username,
            fullName: ucfirst($username),
            email: $email,
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function test_requesting_an_export_creates_a_pending_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');

        $client->request(Request::METHOD_POST, '/account/exports', [
            '_csrf_token' => 'csrf-token',
        ]);
        self::assertResponseRedirects('/account');

        $em->clear();
        /** @var DataExportRepository $repo */
        $repo = static::getContainer()->get(DataExportRepository::class);
        $rows = $repo->findByUser($user);
        self::assertCount(1, $rows);
    }

    public function test_a_second_request_while_pending_does_not_create_another_row(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createVerifiedUser($em, 'alice', 'alice@example.com');

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');

        $client->request(Request::METHOD_POST, '/account/exports', ['_csrf_token' => 'csrf-token']);
        $client->request(Request::METHOD_POST, '/account/exports', ['_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects('/account');

        $client->followRedirect();
        self::assertSelectorTextContains('.lp-flash--error', 'already being prepared');

        $em->clear();
        /** @var DataExportRepository $repo */
        $repo = static::getContainer()->get(DataExportRepository::class);
        $rows = $repo->findByUser($user);
        self::assertCount(1, $rows);
    }

    public function test_anonymous_post_is_redirected_to_login(): void
    {
        $client = static::createClient();
        $client->request(Request::METHOD_POST, '/account/exports', ['_csrf_token' => 'csrf-token']);

        self::assertResponseRedirects('/login');
    }
}
