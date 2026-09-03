<?php

declare(strict_types=1);

namespace App\Module\Account\EventListener;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\DataExportStatus;
use App\Module\Account\Messenger\GenerateDataExportMessage;
use App\Module\Account\Repository\DataExportRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

#[AsEventListener]
final readonly class MarkExportFailedOnFinalFailure
{
    public function __construct(
        private DataExportRepository $dataExports,
        private EntityManagerInterface $em,
        private Auditor $auditor,

        #[Target('export.storage')]
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

        // A Failed export has no expiresAt, so the expiry purge never reaches it
        // — a build that stored the ZIP before a later step exhausted its retries
        // would orphan the archive forever. Deleted here, the one place that
        // observes the terminal failure.
        $exportId = $export->id;
        $archiveOrphaned = false;
        if (null !== $exportId) {
            try {
                $this->exportStorage->delete(DataExport::computeArchiveKey($exportId));
            } catch (FilesystemException) {
                $archiveOrphaned = true;
            }
        }

        $export->fail();
        $this->em->flush();

        // Both records land after the flush, so a failed write leaves the trail
        // saying nothing rather than claiming a status the row never took.
        if ($archiveOrphaned) {
            $this->record('account.data_export_failed_archive_unlink_failed', $message->dataExportId);
        }

        $this->record('account.data_export_failed', $message->dataExportId);
    }

    private function record(string $operation, string $dataExportId): void
    {
        $this->auditor->record(
            $operation,
            AuditOutcome::Failed,
            ['id' => $dataExportId],
            new AuditSubject('data_export', $dataExportId),
        );
    }
}
