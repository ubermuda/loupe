<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes the run rows and the usage rows of every project the departing
 * account owned.
 *
 * ProjectAccountPurger runs first and deletes those projects, which fires
 * DeleteWorkerRunsOnProjectDeleting, so this normally finds nothing. It stays
 * as the backstop the deletion registry asks for: a row whose project went by
 * any other path would otherwise outlive the account.
 */
final readonly class WorkerRunAccountPurger implements AccountDataPurgerInterface
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
        // $user may be detached: read the key as a scalar and never query by the
        // object.
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $connection = $this->em->getConnection();
        $connection->executeStatement(
            'DELETE FROM bridge_worker_runs WHERE project_id IN (SELECT id FROM projects WHERE owner_id = :id)',
            ['id' => $id],
        );
        $connection->executeStatement(
            'DELETE FROM bridge_worker_run_usage WHERE project_id IN (SELECT id FROM projects WHERE owner_id = :id)',
            ['id' => $id],
        );
    }
}
