<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class SuspendUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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
        // out of the log; whether one was given is what the record needs.
        $this->logger->info('account.admin.user_suspended', [
            'targetId' => (string) $target->id,
            'actorId' => (string) $command->actor->id,
            'hasReason' => null !== $target->suspendedReason,
        ]);

        return $target;
    }
}
