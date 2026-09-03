<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Exception\DomainErrors;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\Account\Repository\UserRepository;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

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
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteAccountCommand $command): void
    {
        $user = $this->users->findByAccountDeletionToken($command->token);
        if (!$user instanceof User || !$user->isAccountDeletionTokenValid($command->token)) {
            throw new DomainErrors(['token' => 'account.delete.error.invalid_token']);
        }

        // Read before the purge, which takes the row and the entity's id with
        // it, and recorded after: `account.deleted` names the account, and only
        // this one says the owner confirmed the emailed link themselves.
        $userId = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $this->purger->purge($user);

        $this->auditor->record(
            'account.deletion_confirmed',
            AuditOutcome::Success,
            ['userId' => $userId],
            new AuditSubject('user', $userId),
        );
    }
}
