<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ExpiredExportPurger
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,

        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /** Deletes each expired export's archive file (if present) and row. Returns the purge count. */
    public function purge(): int
    {
        $expired = $this->dataExports->findExpired();

        $purged = 0;
        foreach ($expired as $export) {
            $exportId = $export->id;
            if (null !== $exportId) {
                $path = DataExport::computeArchivePath($this->projectDir, $exportId);
                // A missing file counts as already deleted; an unlink() failure
                // (permissions, read-only fs, I/O error) must NOT remove the row
                // — that would orphan the archive on disk with nothing left to
                // find and retry it on the next run.
                if (is_file($path) && !@unlink($path)) {
                    $this->logger->warning('account.data_export.purge_unlink_failed', ['id' => (string) $exportId]);

                    continue;
                }
            }

            $this->em->remove($export);
            ++$purged;
        }

        $this->em->flush();

        return $purged;
    }
}
