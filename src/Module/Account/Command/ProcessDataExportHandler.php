<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\DataExport\DataExportArchiveBuilder;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Account\Service\DataExportEmailSender;
use App\Module\Account\Service\ExpiredExportPurger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ProcessDataExportHandler
{
    public function __construct(
        private DataExportRepository $dataExports,
        private DataExportArchiveBuilder $builder,
        private DataExportEmailSender $emailSender,
        private ExpiredExportPurger $purger,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessDataExportCommand $command): void
    {
        $export = $this->dataExports->find(Uuid::fromString($command->dataExportId));
        // Ready is reprocessed on redelivery: a previous attempt may have died
        // between the Ready flush and the email send — re-issuing the token
        // invalidates the old link and resends the mail (at-least-once safe).
        if (null === $export || DataExportStatus::Failed === $export->status) {
            $this->logger->info('account.data_export.skipped', ['id' => $command->dataExportId]);

            return;
        }

        // No status change on failure here: flipping to Failed would make the
        // Messenger retries skip this export. Terminal failure is recorded by
        // MarkExportFailedOnFinalFailure once retries are exhausted.
        $exportId = $export->id ?? throw new \LogicException('persisted export always has an id');
        $this->builder->build($export->user, $exportId);

        $rawToken = $export->complete();
        $this->em->flush();
        $this->emailSender->send($export, $rawToken);
        $this->logger->info('account.data_export.completed', ['id' => $command->dataExportId]);

        try {
            $this->purger->purge();
        } catch (\Throwable $e) {
            // A failed opportunistic purge must not fail the export itself —
            // the console command is the backstop for anything missed here.
            $this->logger->warning('account.data_export.purge_failed', ['error' => $e->getMessage()]);
        }
    }
}
