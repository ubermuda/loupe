<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;

/**
 * Validates the emailed deletion token, then hands the user to AccountPurger,
 * which owns the deletion itself and is shared with callers that authorise it
 * some other way.
 */
final readonly class DeleteAccountHandler
{
    public function __construct(
        private UserRepository $users,
        private AccountPurger $purger,
    ) {
    }

    public function __invoke(DeleteAccountCommand $command): void
    {
        $user = $this->users->findByAccountDeletionToken($command->token);
        if (!$user instanceof User || !$user->isAccountDeletionTokenValid($command->token)) {
            throw new DomainErrors(['token' => 'account.delete.error.invalid_token']);
        }

        $this->purger->purge($user);
    }
}
