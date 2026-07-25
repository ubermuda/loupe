<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Controller;

use App\Module\Account\Entity\User;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DataExportDispatchTest extends WebTestCase
{
    public function test_requesting_an_export_queues_one_generate_message(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User(
            username: 'alice',
            fullName: 'Alice A',
            email: 'alice@example.com',
            password: 'hashed-password-placeholder',
        );
        $user->emailVerifiedAt = new \DateTimeImmutable();
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
        $client->request(Request::METHOD_GET, '/account');

        $client->request(Request::METHOD_POST, '/account/exports', ['_csrf_token' => 'csrf-token']);
        self::assertResponseRedirects('/account');

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(GenerateDataExportMessage::class, $sent[0]->getMessage());
    }
}
