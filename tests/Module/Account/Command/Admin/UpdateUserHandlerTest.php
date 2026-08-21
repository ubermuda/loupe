<?php

declare(strict_types=1);

namespace App\Tests\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Command\Admin\UpdateUserCommand;
use App\Module\Account\Command\Admin\UpdateUserHandler;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UpdateUserHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private UpdateUserHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;

        $handler = $container->get(UpdateUserHandler::class);
        self::assertInstanceOf(UpdateUserHandler::class, $handler);
        $this->handler = $handler;
    }

    public function test_it_persists_a_name_change(): void
    {
        $actor = $this->seedUser('update-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('update-target@example.com');

        ($this->handler)($this->command($target, $actor, fullName: '  Zaphod Beeblebrox  '));

        $this->em->clear();
        self::assertSame('Zaphod Beeblebrox', $this->reload($target)->fullName);
    }

    public function test_it_grants_and_revokes_the_admin_role(): void
    {
        $actor = $this->seedUser('roles-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('roles-target@example.com');

        ($this->handler)($this->command($target, $actor, isAdmin: true));
        $this->em->clear();
        self::assertContains('ROLE_ADMIN', $this->reload($target)->roles);

        $target = $this->reload($target);
        $actor = $this->reload($actor);
        ($this->handler)($this->command($target, $actor, isAdmin: false));
        $this->em->clear();
        self::assertNotContains('ROLE_ADMIN', $this->reload($target)->roles);
    }

    public function test_an_email_change_clears_verification_and_queues_one_email(): void
    {
        $actor = $this->seedUser('email-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('email-target@example.com');
        $target->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        // isVerified stays true on purpose: the new address must be unverified
        // regardless of what the checkbox said.
        ($this->handler)($this->command($target, $actor, email: 'Email-Moved@Example.com', isVerified: true));

        self::assertQueuedEmailCount(1);

        $this->em->clear();
        $reloaded = $this->reload($target);
        self::assertSame('email-moved@example.com', $reloaded->email);
        self::assertNull($reloaded->emailVerifiedAt);
    }

    public function test_a_case_only_email_edit_keeps_verification_and_sends_nothing(): void
    {
        $actor = $this->seedUser('case-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('case-target@example.com');
        $target->emailVerifiedAt = new \DateTimeImmutable();
        $this->em->flush();

        ($this->handler)($this->command($target, $actor, email: 'CASE-TARGET@example.com', isVerified: true));

        self::assertQueuedEmailCount(0);

        $this->em->clear();
        self::assertNotNull($this->reload($target)->emailVerifiedAt);
    }

    public function test_the_verified_checkbox_drives_verification_when_the_email_is_unchanged(): void
    {
        $actor = $this->seedUser('verify-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('verify-target@example.com');

        ($this->handler)($this->command($target, $actor, isVerified: true));
        $this->em->clear();
        self::assertNotNull($this->reload($target)->emailVerifiedAt);

        $target = $this->reload($target);
        $actor = $this->reload($actor);
        ($this->handler)($this->command($target, $actor, isVerified: false));
        $this->em->clear();
        self::assertNull($this->reload($target)->emailVerifiedAt);
    }

    public function test_a_duplicate_email_throws_a_field_error(): void
    {
        $actor = $this->seedUser('dup-actor@example.com', ['ROLE_ADMIN']);
        $target = $this->seedUser('dup-target@example.com');
        $this->seedUser('dup-taken@example.com');

        try {
            ($this->handler)($this->command($target, $actor, email: 'dup-taken@example.com'));
            self::fail('Expected DomainErrors for a duplicate email.');
        } catch (DomainErrors $e) {
            self::assertSame(['email' => 'account.admin.users.error.email_taken'], $e->errors);
        }

        $this->em->clear();
        self::assertSame('dup-target@example.com', $this->reload($target)->email);
    }

    public function test_a_guard_violation_propagates_and_changes_nothing(): void
    {
        $actor = $this->seedUser('guard-actor@example.com', ['ROLE_ADMIN']);

        try {
            ($this->handler)($this->command($actor, $actor, fullName: 'Renamed', isAdmin: false));
            self::fail('Expected DomainErrors when an admin demotes themselves.');
        } catch (DomainErrors $e) {
            self::assertSame(['roles' => 'account.admin.users.error.self_demote'], $e->errors);
        }

        $this->em->clear();
        $reloaded = $this->reload($actor);
        self::assertSame('Test User', $reloaded->fullName);
        self::assertContains('ROLE_ADMIN', $reloaded->roles);
    }

    private function command(
        User $target,
        User $actor,
        ?string $fullName = null,
        ?string $email = null,
        ?bool $isAdmin = null,
        bool $isVerified = false,
    ): UpdateUserCommand {
        return new UpdateUserCommand(
            target: $target,
            actor: $actor,
            fullName: $fullName ?? $target->fullName,
            email: $email ?? $target->email,
            isAdmin: $isAdmin ?? in_array('ROLE_ADMIN', $target->roles, true),
            isVerified: $isVerified,
        );
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
