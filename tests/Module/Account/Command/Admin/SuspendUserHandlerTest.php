<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\SuspendUserCommand;
use App\Module\Account\Command\Admin\SuspendUserHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SuspendUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SuspendUserHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = $container->get(SuspendUserHandler::class);
        self::assertInstanceOf(SuspendUserHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_stores_the_timestamp_reason_and_acting_admin(): void
    {
        $actor = $this->seedUser('suspend-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('suspend-target@example.com');

        ($this->handler)(new SuspendUserCommand($target, $actor, '  Repeated spam  '));

        $this->em->clear();
        $reloaded = $this->reload($target);
        self::assertNotNull($reloaded->suspendedAt);
        self::assertSame('Repeated spam', $reloaded->suspendedReason);
        self::assertNotNull($reloaded->suspendedBy);
        self::assertSame('suspend-actor@example.com', $reloaded->suspendedBy->email);
        self::assertTrue($reloaded->isSuspended());
    }

    /** @return iterable<string, array{?string}> */
    public static function blankReasons(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'whitespace only' => ["  \n\t "];
    }

    #[DataProvider('blankReasons')]
    public function test_a_blank_reason_is_stored_as_null(?string $reason): void
    {
        $actor = $this->seedUser('blank-actor-'.md5((string) $reason).'@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('blank-target-'.md5((string) $reason).'@example.com');

        ($this->handler)(new SuspendUserCommand($target, $actor, $reason));

        $this->em->clear();
        $reloaded = $this->reload($target);
        self::assertNotNull($reloaded->suspendedAt);
        self::assertNull($reloaded->suspendedReason);
    }

    public function test_it_refuses_the_agent_account(): void
    {
        $actor = $this->seedUser('agent-actor@example.com', ['ROLE_ADMIN']);
        $agent = $this->em->find(User::class, Uuid::fromString(User::AGENT_ID));
        self::assertInstanceOf(User::class, $agent);

        $this->expectDomainError($agent, $actor, 'account.admin.users.error.agent_account');

        $this->em->clear();
        self::assertNull($this->reload($agent)->suspendedAt);
    }

    public function test_it_refuses_the_acting_admin(): void
    {
        $actor = $this->seedUser('self-actor@example.com', ['ROLE_ADMIN']);

        $this->expectDomainError($actor, $actor, 'account.admin.users.error.self_target');

        $this->em->clear();
        self::assertNull($this->reload($actor)->suspendedAt);
    }

    public function test_it_refuses_the_last_active_admin(): void
    {
        // The acting admin is deliberately not an admin row here: what the rule
        // protects is the count of reachable administrators, not who asks.
        $actor = $this->seedUser('quorum-actor@example.com');
        $target = $this->seedUser('quorum-target@example.com', ['ROLE_ADMIN']);

        $this->expectDomainError($target, $actor, 'account.admin.users.error.last_admin');

        $this->em->clear();
        self::assertNull($this->reload($target)->suspendedAt);
    }

    public function test_it_suspends_an_admin_while_another_remains_active(): void
    {
        $actor = $this->seedUser('quorum-two-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('quorum-two-target@example.com', ['ROLE_ADMIN']);

        ($this->handler)(new SuspendUserCommand($target, $actor, 'Sharing credentials'));

        $this->em->clear();
        self::assertNotNull($this->reload($target)->suspendedAt);
    }

    private function expectDomainError(User $target, User $actor, string $expectedKey): void
    {
        try {
            ($this->handler)(new SuspendUserCommand($target, $actor, 'because'));
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

    private function reload(User $user): User
    {
        $id = $user->id ?? throw new \LogicException('seeded user has no id');
        $reloaded = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
