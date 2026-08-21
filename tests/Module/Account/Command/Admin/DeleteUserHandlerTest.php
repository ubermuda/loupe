<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\DeleteUserCommand;
use App\Module\Account\Command\Admin\DeleteUserHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DeleteUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DeleteUserHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = $container->get(DeleteUserHandler::class);
        self::assertInstanceOf(DeleteUserHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_removes_the_row(): void
    {
        $actor = $this->seedUser('delete-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('delete-target@example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        ($this->handler)(new DeleteUserCommand($target, $actor, $target->email));

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $targetId));
    }

    public function test_a_mismatched_confirmation_deletes_nothing(): void
    {
        $actor = $this->seedUser('confirm-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('confirm-target@example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        try {
            ($this->handler)(new DeleteUserCommand($target, $actor, 'confirm-actor@example.com'));
            self::fail('Expected DomainErrors for a mismatched confirmation.');
        } catch (DomainErrors $e) {
            self::assertSame(['confirmEmail' => 'account.admin.users.error.email_mismatch'], $e->errors);
        }

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $targetId));
    }

    public function test_an_empty_confirmation_deletes_nothing(): void
    {
        $actor = $this->seedUser('empty-confirm-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('empty-confirm-target@example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        try {
            ($this->handler)(new DeleteUserCommand($target, $actor, ''));
            self::fail('Expected DomainErrors for an empty confirmation.');
        } catch (DomainErrors $e) {
            self::assertSame(['confirmEmail' => 'account.admin.users.error.email_mismatch'], $e->errors);
        }

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $targetId));
    }

    /** The stored address is lowercase, and an admin retyping it is not being tested on caps. */
    public function test_the_confirmation_ignores_case_and_surrounding_space(): void
    {
        $actor = $this->seedUser('case-confirm-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('case-confirm-target@example.com');
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        ($this->handler)(new DeleteUserCommand($target, $actor, '  Case-Confirm-Target@Example.com '));

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $targetId));
    }

    /** suspended_by is ON DELETE SET NULL, so an admin who suspended someone is still deletable. */
    public function test_it_removes_an_admin_who_suspended_another_account(): void
    {
        $actor = $this->seedUser('delete-susp-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('delete-susp-target@example.com', ['ROLE_ADMIN']);
        $suspended = $this->seedUser('delete-susp-victim@example.com');
        $suspended->suspendedAt = new \DateTimeImmutable();
        $suspended->suspendedBy = $target;
        $this->em->flush();

        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');
        $suspendedId = $suspended->id ?? throw new \LogicException('seeded user has no id');

        ($this->handler)(new DeleteUserCommand($target, $actor, $target->email));

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $targetId));
        $survivor = $this->em->find(User::class, $suspendedId);
        self::assertInstanceOf(User::class, $survivor);
        self::assertNull($survivor->suspendedBy);
    }

    public function test_it_refuses_the_agent_account(): void
    {
        $actor = $this->seedUser('delete-agent-actor@example.com', ['ROLE_ADMIN']);
        $agent = $this->em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);

        $this->expectDomainError($agent, $actor, 'account.admin.users.error.agent_account');

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, Uuid::fromString(User::AGENT_ID)));
    }

    public function test_it_refuses_the_acting_admin(): void
    {
        $actor = $this->seedUser('delete-self@example.com', ['ROLE_ADMIN']);
        $actorId = $actor->id ?? throw new \LogicException('seeded user has no id');

        $this->expectDomainError($actor, $actor, 'account.admin.users.error.self_target');

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $actorId));
    }

    public function test_it_refuses_the_last_active_admin(): void
    {
        $actor = $this->seedUser('delete-quorum-actor@example.com');
        $target = $this->seedUser('delete-quorum-admin@example.com', ['ROLE_ADMIN']);
        $targetId = $target->id ?? throw new \LogicException('seeded user has no id');

        $this->expectDomainError($target, $actor, 'account.admin.users.error.last_admin');

        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $targetId));
    }

    private function expectDomainError(User $target, User $actor, string $expectedKey): void
    {
        try {
            ($this->handler)(new DeleteUserCommand($target, $actor, $target->email));
            self::fail('Expected DomainErrors, none thrown.');
        } catch (DomainErrors $e) {
            self::assertContains($expectedKey, $e->errors);
        }
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, array $roles = []): User
    {
        $user = new User(fullName: 'Test User', email: $email, password: 'irrelevant-hash');
        $user->roles = $roles;
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
