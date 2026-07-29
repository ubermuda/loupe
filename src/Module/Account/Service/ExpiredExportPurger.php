<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;

readonly class ExpiredExportPurger
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private FilesystemOperator $exportStorage,
    ) {
    }

    /** Deletes each expired export's archive (if present) and row. Returns the purge count. */
    public function purge(): int
    {
        $expired = $this->dataExports->findExpired();

        $purged = 0;
        foreach ($expired as $export) {
            $exportId = $export->id;
            if (null !== $exportId) {
                // Flysystem treats a missing object as already deleted. A real
                // failure (permissions, an unreachable bucket) must NOT remove
                // the row — that would orphan the archive with nothing left to
                // find and retry it on the next run.
                try {
                    $this->exportStorage->delete(DataExport::computeArchiveKey($exportId));
                } catch (FilesystemException) {
                    $this->logger->warning('account.data_export.purge_unlink_failed', ['id' => (string) $exportId]);

                    continue;
                }
            }

            $this->em->remove($export);
            ++$purged;
        }

        $this->em->flush();

        // The scheduler runs this through RunCommandMessage, which buffers and
        // discards the command's own output — this line is the only thing that
        // makes hourly purge liveness greppable in the worker logs.
        $this->logger->info('account.data_export.purge_completed', ['purged' => $purged]);

        return $purged;
    }
}
