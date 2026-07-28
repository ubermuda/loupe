<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Command\PromoteUserToAdminCommand;
use App\Module\Account\Command\PromoteUserToAdminHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PromoteUserToAdminHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private PromoteUserToAdminHandler $handler;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = self::getContainer()->get(PromoteUserToAdminHandler::class);
        self::assertInstanceOf(PromoteUserToAdminHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_grants_the_role_and_reports_the_change(): void
    {
        $user = $this->persistUser('promote-me@example.com');

        self::assertTrue(($this->handler)(new PromoteUserToAdminCommand('promote-me@example.com')));

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id);
        self::assertNotNull($reloaded);
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());
    }

    public function test_promoting_an_administrator_again_changes_nothing(): void
    {
        $user = $this->persistUser('already-admin@example.com');
        $user->roles = ['ROLE_ADMIN'];
        $this->em->flush();

        self::assertFalse(($this->handler)(new PromoteUserToAdminCommand('already-admin@example.com')));
    }

    public function test_it_keeps_roles_the_account_already_had(): void
    {
        $user = $this->persistUser('multi-role@example.com');
        $user->roles = ['ROLE_SUPPORT'];
        $this->em->flush();

        ($this->handler)(new PromoteUserToAdminCommand('multi-role@example.com'));

        $this->em->clear();
        $reloaded = $this->em->find(User::class, $user->id);
        self::assertNotNull($reloaded);
        self::assertContains('ROLE_SUPPORT', $reloaded->getRoles());
        self::assertContains('ROLE_ADMIN', $reloaded->getRoles());
    }

    public function test_an_unknown_email_is_a_domain_error(): void
    {
        $this->expectException(DomainErrors::class);

        ($this->handler)(new PromoteUserToAdminCommand('nobody@example.com'));
    }

    /** @param non-empty-string $email */
    private function persistUser(string $email): User
    {
        $user = new User(username: explode('@', $email)[0], fullName: 'Test User', email: $email);
        $user->password = 'not-a-real-hash';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
