<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Grants ROLE_ADMIN to an existing account. This is the recovery path for an
 * instance whose only administrator is unreachable — the install wizard closes
 * permanently once any account exists, so without this there is no way back in.
 */
final readonly class PromoteUserToAdminHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool true when the role was added, false when the account already had it
     *
     * @throws DomainErrors when no account matches the email
     */
    public function __invoke(PromoteUserToAdminCommand $command): bool
    {
        $user = $this->users->findOneByEmail($command->email)
            ?? throw new DomainErrors(['email' => 'account.console.error.user_not_found']);

        if (in_array('ROLE_ADMIN', $user->roles, true)) {
            return false;
        }

        // Append rather than assign: an account may carry other roles that a
        // promotion has no business dropping.
        $user->roles[] = 'ROLE_ADMIN';
        $this->em->flush();

        $this->logger->info('account.user.promoted_to_admin', ['userId' => (string) $user->id]);

        return true;
    }
}
