<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Exception\DomainErrors;
use App\Module\Account\Admin\AdminUserGuard;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UnsuspendUserHandler
{
    public function __construct(
        private AdminUserGuard $guard,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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

        $this->logger->info('account.admin.user_unsuspended', [
            'targetId' => (string) $target->id,
            'actorId' => (string) $command->actor->id,
        ]);

        return $target;
    }
}
