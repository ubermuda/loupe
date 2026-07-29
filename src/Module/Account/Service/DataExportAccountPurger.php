<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Deletion\AccountDataPurgerInterface;
use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Data export archives live in the export storage, keyed by export id; collect
 * their keys before removing the rows so they can be deleted only after a
 * successful commit (a rollback must not have already destroyed archives a
 * still-existing row points at).
 */
final readonly class DataExportAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function deletionOrder(): int
    {
        return 20;
    }

    #[\Override]
    public function purge(User $user, AccountDeletionCleanup $cleanup): void
    {
        $conn = $this->em->getConnection();
        $id = (string) ($user->id ?? throw new \LogicException('a persisted user always has an id'));

        $exportIds = array_map(strval(...), $conn->fetchFirstColumn('SELECT id FROM data_exports WHERE user_id = :id', ['id' => $id]));
        foreach ($exportIds as $exportId) {
            $cleanup->scheduleArchiveDeletion(DataExport::computeArchiveKey(Uuid::fromString($exportId)));
        }
        $conn->executeStatement('DELETE FROM data_exports WHERE user_id = :id', ['id' => $id]);
    }
}
