<?php

declare(strict_types=1);

namespace App\Module\Account\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;

/**
 * The preconditions every admin-initiated change to an account must clear.
 * They exist because the install wizard closes permanently once any account
 * exists: an instance left with no reachable administrator has no way back in.
 */
final readonly class AdminUserGuard
{
    public function __construct(
        private UserRepository $users,
    ) {
    }

    public function assertMutable(User $target): void
    {
        if ($target->isAgent()) {
            throw new DomainErrors(['user' => 'account.admin.users.error.agent_account']);
        }
    }

    public function assertDeletable(User $target, User $actor): void
    {
        $this->assertMutable($target);
        $this->assertNotSelf($target, $actor);
        $this->assertQuorumSurvives($target);
    }

    public function assertSuspendable(User $target, User $actor): void
    {
        $this->assertMutable($target);
        $this->assertNotSelf($target, $actor);
        $this->assertQuorumSurvives($target);
    }

    /** @param list<string> $newRoles */
    public function assertRolesAssignable(User $target, User $actor, array $newRoles): void
    {
        $this->assertMutable($target);

        if (!$this->isAdmin($target) || in_array('ROLE_ADMIN', $newRoles, true)) {
            return;
        }

        if ($this->isSelf($target, $actor)) {
            throw new DomainErrors(['roles' => 'account.admin.users.error.self_demote']);
        }

        $this->assertQuorumSurvives($target);
    }

    private function assertNotSelf(User $target, User $actor): void
    {
        if ($this->isSelf($target, $actor)) {
            throw new DomainErrors(['user' => 'account.admin.users.error.self_target']);
        }
    }

    /**
     * Only a target that currently counts toward the quorum can empty it, so a
     * non-admin or an already-suspended admin is always safe to act on.
     */
    private function assertQuorumSurvives(User $target): void
    {
        if (!$this->isAdmin($target) || $target->isSuspended()) {
            return;
        }

        if ($this->users->countActiveAdmins() > 1) {
            return;
        }

        throw new DomainErrors(['user' => 'account.admin.users.error.last_admin']);
    }

    private function isSelf(User $target, User $actor): bool
    {
        $actorId = $actor->id ?? throw new \LogicException('actor must be persisted');

        return true === $target->id?->equals($actorId);
    }

    private function isAdmin(User $target): bool
    {
        return in_array('ROLE_ADMIN', $target->roles, true);
    }
}
