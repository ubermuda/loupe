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

final readonly class SuspendUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws DomainErrors when the guard refuses the suspension */
    public function __invoke(SuspendUserCommand $command): User
    {
        $target = $command->target;

        $this->guard->assertSuspendable($target, $command->actor);

        $reason = trim($command->reason ?? '');

        $target->suspendedAt = new \DateTimeImmutable();
        $target->suspendedReason = '' === $reason ? null : $reason;
        $target->suspendedBy = $command->actor;

        $this->em->flush();

        // The reason itself is free-form admin prose about a person and stays
        // out of the record; whether one was given is what the record needs.
        $this->auditor->record(
            'account.admin_user_suspended',
            AuditOutcome::Success,
            [
                'userId' => (string) $target->id,
                'hasReason' => null !== $target->suspendedReason,
            ],
            new AuditSubject('user', (string) $target->id),
        );

        return $target;
    }
}
