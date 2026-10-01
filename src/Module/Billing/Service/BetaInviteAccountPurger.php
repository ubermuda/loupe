<?php

declare(strict_types=1);

namespace App\Module\Billing\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the invite rows and forgets the departing account on them, so the
 * admin list still shows a link as used after its tester leaves.
 */
final readonly class BetaInviteAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /** After ProjectAccountPurger at 10, which is the only ordering constraint. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 85;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        // $user may be detached after ProjectAccountPurger: read the key as a scalar.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE beta_invites SET redeemed_by_id = NULL WHERE redeemed_by_id = :id', ['id' => $id]);
        $connection->executeStatement('UPDATE beta_invites SET created_by_id = NULL WHERE created_by_id = :id', ['id' => $id]);
    }
}
