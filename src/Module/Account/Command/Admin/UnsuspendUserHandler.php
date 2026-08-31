<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Entity\User;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UnsuspendUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the target is the agent account */
    public function __invoke(UnsuspendUserCommand $command): User
    {
        $target = $command->target;

        // assertMutable only: reinstating an account can lock nobody out, so
        // the self and last-admin rules would only trap a suspended sole admin.
        $this->guard->assertMutable($target);

        $target->suspendedAt = null;
        $target->suspendedReason = null;
        $target->suspendedBy = null;

        $this->em->flush();

        $this->auditor->record(
            'account.admin.user_unsuspended',
            AuditOutcome::Success,
            ['userId' => (string) $target->id],
            new AuditSubject('user', (string) $target->id),
        );

        return $target;
    }
}
