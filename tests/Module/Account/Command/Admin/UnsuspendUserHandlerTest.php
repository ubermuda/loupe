<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\UnsuspendUserCommand;
use App\Module\Account\Command\Admin\UnsuspendUserHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class UnsuspendUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UnsuspendUserHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = $container->get(UnsuspendUserHandler::class);
        self::assertInstanceOf(UnsuspendUserHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_clears_the_timestamp_reason_and_acting_admin(): void
    {
        $actor = $this->seedUser('unsuspend-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('unsuspend-target@example.com');
        $this->suspend($target, $actor);

        ($this->handler)(new UnsuspendUserCommand($target, $actor));

        $this->em->clear();
        $reloaded = $this->reload($target);
        self::assertNull($reloaded->suspendedAt);
        self::assertNull($reloaded->suspendedReason);
        self::assertNull($reloaded->suspendedBy);
        self::assertFalse($reloaded->isSuspended());
    }

    /**
     * The whole point of unsuspend using assertMutable alone: a suspended sole
     * admin must be reinstatable, or the instance has locked itself out.
     */
    public function test_it_reinstates_the_only_administrator(): void
    {
        $actor = $this->seedUser('lockout-actor@example.com');
        $target = $this->seedUser('lockout-admin@example.com', ['ROLE_ADMIN']);
        $this->suspend($target, $actor);

        ($this->handler)(new UnsuspendUserCommand($target, $actor));

        $this->em->clear();
        self::assertNull($this->reload($target)->suspendedAt);
    }

    /** Reinstating yourself is legitimate — only the agent account is refused. */
    public function test_it_reinstates_the_acting_admin(): void
    {
        $actor = $this->seedUser('self-unsuspend@example.com', ['ROLE_ADMIN']);
        $this->suspend($actor, $actor);

        ($this->handler)(new UnsuspendUserCommand($actor, $actor));

        $this->em->clear();
        self::assertNull($this->reload($actor)->suspendedAt);
    }

    public function test_it_refuses_the_agent_account(): void
    {
        $actor = $this->seedUser('unsuspend-agent-actor@example.com', ['ROLE_ADMIN']);
        $agent = $this->em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);
        $this->suspend($agent, $actor);

        try {
            ($this->handler)(new UnsuspendUserCommand($agent, $actor));
            self::fail('Expected DomainErrors for the agent account.');
        } catch (DomainErrors $e) {
            self::assertContains('account.admin.users.error.agent_account', $e->errors);
        }

        $this->em->clear();
        self::assertNotNull($this->reload($agent)->suspendedAt);
    }

    private function suspend(User $target, User $actor): void
    {
        $target->suspendedAt = new \DateTimeImmutable();
        $target->suspendedReason = 'Repeated spam';
        $target->suspendedBy = $actor;
        $this->em->flush();
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

    private function reload(User $user): User
    {
        $id = $user->id ?? throw new \LogicException('seeded user has no id');
        $reloaded = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
