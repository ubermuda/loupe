<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ExpiredExportPurger
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,

        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /** Deletes each expired export's archive file (if present) and row. Returns the purge count. */
    public function purge(): int
    {
        $expired = $this->dataExports->findExpired();

        foreach ($expired as $export) {
            $exportId = $export->id;
            if (null !== $exportId) {
                $path = DataExport::computeArchivePath($this->projectDir, $exportId);
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $this->em->remove($export);
        }

        $this->em->flush();

        return \count($expired);
    }
}
