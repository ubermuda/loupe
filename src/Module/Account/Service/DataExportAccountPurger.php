<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\AccountDeletion\AccountDataPurgerInterface;
use App\AccountDeletion\AccountDeletionCleanup;
use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Data export archive files live on disk under var/exports/; collect their
 * paths before removing the rows so they can be unlinked only after a
 * successful commit (a rollback must not have already destroyed files a
 * still-existing row points at).
 */
final readonly class DataExportAccountPurger implements AccountDataPurgerInterface
{
    public function __construct(
        private EntityManagerInterface $em,

        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
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
            $cleanup->scheduleFileUnlink(DataExport::computeArchivePath($this->projectDir, Uuid::fromString($exportId)));
        }
        $conn->executeStatement('DELETE FROM data_exports WHERE user_id = :id', ['id' => $id]);
    }
}
