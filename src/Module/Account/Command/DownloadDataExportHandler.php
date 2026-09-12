<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\DataExport;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Decides whether this caller may have this archive, and opens it. Null is the
 * one refusal answer: a denied request and a missing object both leave the
 * caller with nothing, and telling them apart would say whether the export
 * exists.
 */
final readonly class DownloadDataExportHandler
{
    public function __construct(
        #[Target('export.storage')]
        private FilesystemOperator $exportStorage,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DownloadDataExportCommand $command): ?DownloadDataExportView
    {
        $export = $command->export;

        // Ownership is what authorises the download, so the signed-in owner needs no
        // token — the emailed link's token stays honoured, and stays wrong when it
        // does not match. The 48-hour window is a gate of its own either way.
        $tokenAccepted = '' === $command->token || $export->isDownloadTokenValid($command->token);

        if (null === $export->user->id || null === $command->user->id
            || !$export->user->id->equals($command->user->id)
            || !$export->isDownloadable()
            || !$tokenAccepted) {
            $this->auditor->record(
                'account.data_export_download_denied',
                AuditOutcome::Refused,
                ['id' => (string) $export->id],
                new AuditSubject('data_export', (string) $export->id),
                Auditor::CATEGORY_SECURITY,
            );

            return null;
        }

        $exportId = $export->id ?? throw new \LogicException('resolved export always has an id');

        try {
            $stream = $this->exportStorage->readStream(DataExport::computeArchiveKey($exportId));
        } catch (FilesystemException) {
            return null;
        }

        return new DownloadDataExportView($stream, sprintf('loupe-export-%s.zip', (string) $exportId));
    }
}
