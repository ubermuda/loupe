<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the bridge rows of the departing account. A bridge row belongs to the
 * account rather than to a project, so deleting the projects leaves it behind.
 */
final readonly class BridgeAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /** After ProjectAccountPurger at 10, which is the only ordering constraint. */
    #[\Override]
    public function deletionOrder(): int
    {
        return 15;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        // ProjectAccountPurger runs first and calls EntityManager::clear(), so
        // $user may be detached: read the key as a scalar.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $this->em->getConnection()->executeStatement('DELETE FROM bridges WHERE owner_id = :id', ['id' => $id]);
    }
}
