<?php

declare(strict_types=1);

namespace App\Module\Account\Messenger;

use App\DataExport\DataExportArchiveBuilder;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Account\Service\DataExportEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class GenerateDataExportHandler
{
    public function __construct(
        private DataExportRepository $dataExports,
        private DataExportArchiveBuilder $builder,
        private DataExportEmailSender $emailSender,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateDataExportMessage $message): void
    {
        $export = $this->dataExports->find(Uuid::fromString($message->dataExportId));
        // Ready is reprocessed on redelivery: a previous attempt may have died
        // between the Ready flush and the email send — re-issuing the token
        // invalidates the old link and resends the mail (at-least-once safe).
        if (null === $export || DataExportStatus::Failed === $export->status) {
            $this->logger->info('account.data_export.skipped', ['id' => $message->dataExportId]);

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
        $this->logger->info('account.data_export.completed', ['id' => $message->dataExportId]);
    }
}
