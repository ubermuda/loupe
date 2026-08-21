<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminUserGuardTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    private UserRepository $users;

    private AdminUserGuard $guard;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $users = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $this->users = $users;

        $this->guard = new AdminUserGuard($this->users);
    }

    public function test_every_mutation_refuses_the_agent_account(): void
    {
        $agent = $this->users->agent();
        $actor = $this->seedUser('actor@example.com', ['ROLE_ADMIN']);

        $key = 'account.admin.users.error.agent_account';
        $this->assertDomainError('user', $key, fn () => $this->guard->assertMutable($agent));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertDeletable($agent, $actor));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertSuspendable($agent, $actor));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertRolesAssignable($agent, $actor, ['ROLE_ADMIN']));
    }

    public function test_deleting_or_suspending_yourself_throws(): void
    {
        $actor = $this->seedUser('self@example.com', ['ROLE_ADMIN']);
        $this->seedUser('other-admin@example.com', ['ROLE_ADMIN']);

        $key = 'account.admin.users.error.self_target';
        $this->assertDomainError('user', $key, fn () => $this->guard->assertDeletable($actor, $actor));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertSuspendable($actor, $actor));
    }

    public function test_revoking_your_own_admin_role_throws(): void
    {
        $actor = $this->seedUser('self-demote@example.com', ['ROLE_ADMIN']);
        $this->seedUser('spare-admin@example.com', ['ROLE_ADMIN']);

        $this->assertDomainError(
            'roles',
            'account.admin.users.error.self_demote',
            fn () => $this->guard->assertRolesAssignable($actor, $actor, []),
        );
    }

    public function test_the_last_active_admin_cannot_be_deleted_suspended_or_demoted(): void
    {
        $actor = $this->seedUser('operator@example.com');
        $first = $this->seedUser('first-admin@example.com', ['ROLE_ADMIN']);
        $second = $this->seedUser('second-admin@example.com', ['ROLE_ADMIN']);

        self::assertSame(2, $this->users->countActiveAdmins());
        $this->guard->assertDeletable($first, $actor);

        $second->roles = [];
        $this->em->flush();
        self::assertSame(1, $this->users->countActiveAdmins());

        $key = 'account.admin.users.error.last_admin';
        $this->assertDomainError('user', $key, fn () => $this->guard->assertDeletable($first, $actor));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertSuspendable($first, $actor));
        $this->assertDomainError('user', $key, fn () => $this->guard->assertRolesAssignable($first, $actor, []));
    }

    public function test_a_non_admin_target_is_deletable_while_a_single_admin_exists(): void
    {
        $actor = $this->seedUser('only-admin@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('plain@example.com');

        self::assertSame(1, $this->users->countActiveAdmins());
        $this->guard->assertDeletable($target, $actor);
        $this->guard->assertSuspendable($target, $actor);
    }

    public function test_a_suspended_admin_does_not_count_toward_the_quorum(): void
    {
        $actor = $this->seedUser('operator@example.com');
        $active = $this->seedUser('active-admin@example.com', ['ROLE_ADMIN']);
        $suspended = $this->seedUser('suspended-admin@example.com', ['ROLE_ADMIN'], suspended: true);

        self::assertSame(1, $this->users->countActiveAdmins());

        $this->assertDomainError(
            'user',
            'account.admin.users.error.last_admin',
            fn () => $this->guard->assertDeletable($active, $actor),
        );

        $this->guard->assertDeletable($suspended, $actor);
    }

    public function test_the_agent_never_counts_as_an_active_admin(): void
    {
        $this->seedUser('lone-admin@example.com', ['ROLE_ADMIN']);

        $agent = $this->users->agent();
        $agent->roles = ['ROLE_ADMIN'];
        $this->em->flush();

        self::assertSame(1, $this->users->countActiveAdmins());
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function seedUser(string $email, array $roles = [], bool $suspended = false): User
    {
        $user = new User(fullName: $email, email: $email, password: 'hashed-password-placeholder');
        $user->roles = $roles;
        $user->emailVerifiedAt = new \DateTimeImmutable();
        if ($suspended) {
            $user->suspendedAt = new \DateTimeImmutable();
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function assertDomainError(string $field, string $key, callable $call): void
    {
        try {
            $call();
        } catch (DomainErrors $e) {
            self::assertSame([$field => $key], $e->errors);

            return;
        }

        self::fail(sprintf('Expected DomainErrors "%s", none was thrown.', $key));
    }
}
