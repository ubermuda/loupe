<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class MarkExportFailedOnFinalFailure
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private FilesystemOperator $exportStorage,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof GenerateDataExportMessage || $event->willRetry()) {
            return;
        }

        $export = $this->dataExports->find(Uuid::fromString($message->dataExportId));
        if (null === $export || DataExportStatus::Failed === $export->status) {
            return;
        }

        // A Failed export has no expiresAt, so ExpiredExportPurger's
        // findExpired() (expiresAt-based) would never reach it — a build
        // that got as far as storing the ZIP before a later step (flush,
        // email) exhausted its retries would otherwise orphan that archive
        // forever. Delete it here, the one place that observes the terminal
        // failure.
        $exportId = $export->id;
        if (null !== $exportId) {
            try {
                $this->exportStorage->delete(DataExport::computeArchiveKey($exportId));
            } catch (FilesystemException) {
                $this->logger->warning('account.data_export.failed_archive_unlink_failed', ['id' => $message->dataExportId]);
            }
        }

        $export->fail();
        $this->em->flush();
        $this->logger->warning('account.data_export.failed', ['id' => $message->dataExportId]);
    }
}
