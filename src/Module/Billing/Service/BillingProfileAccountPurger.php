<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes the user's billing profile row. Any Stripe cancellation for this
 * profile is handled separately by DeleteAccountHandler — it reads the
 * subscription id before the deletion transaction starts and dispatches the
 * cancellation only after the transaction has durably committed, so this
 * purger has nothing to do beyond removing the row itself.
 */
final readonly class BillingProfileAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 80;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));
        $this->em->getConnection()->executeStatement('DELETE FROM billing_profiles WHERE user_id = :id', ['id' => $id]);
    }
}
