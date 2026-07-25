<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Module\Account\Command\JoinWaitlistCommand;
use App\Module\Account\Command\JoinWaitlistHandler;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class JoinWaitlistHandlerTest extends KernelTestCase
{
    public function test_creates_entry_for_new_email(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('new@example.com'));

        self::assertNotNull($repo->findOneByEmail('new@example.com'));
    }

    public function test_duplicate_email_is_silently_ignored(): void
    {
        self::bootKernel();
        $handler = self::getContainer()->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = self::getContainer()->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('dup@example.com'));
        $handler(new JoinWaitlistCommand('DUP@example.com'));

        self::assertCount(1, $repo->findBy(['email' => 'dup@example.com']));
    }

    public function test_an_address_that_already_has_an_account_is_not_added(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->persist(new User(username: 'already-registered', fullName: 'Already Registered', email: 'already-registered@example.com', password: 'x'));
        $em->flush();

        $handler = $container->get(JoinWaitlistHandler::class);
        self::assertInstanceOf(JoinWaitlistHandler::class, $handler);
        $repo = $container->get(WaitlistEntryRepository::class);
        self::assertInstanceOf(WaitlistEntryRepository::class, $repo);

        $handler(new JoinWaitlistCommand('already-registered@example.com'));

        self::assertNull($repo->findOneByEmail('already-registered@example.com'));
    }
}
