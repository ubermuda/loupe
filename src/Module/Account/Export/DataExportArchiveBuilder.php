<?php

declare(strict_types=1);

namespace App\Module\Account\Export;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

readonly class DataExportArchiveBuilder
{
    public function __construct(
        /** @var iterable<UserDataExporterInterface> */
        #[AutowireIterator('app.user_data_exporter')]
        private iterable $exporters,
        private FilesystemOperator $exportStorage,
    ) {
    }

    /** Builds the archive, stores it, and returns its export-storage key. */
    public function build(User $user, Uuid $exportId): string
    {
        // ZipArchive can only write to a real local file, so the archive is
        // always assembled locally and uploaded afterwards — even when the
        // export storage is a bucket.
        $localPath = tempnam(sys_get_temp_dir(), 'loupe-export-');
        if (false === $localPath) {
            throw new \RuntimeException('Cannot create a temporary file for the export archive.');
        }

        $key = DataExport::computeArchiveKey($exportId);

        try {
            $this->writeArchive($user, $localPath);
            $this->upload($localPath, $key);
        } finally {
            @unlink($localPath);
        }

        return $key;
    }

    private function writeArchive(User $user, string $localPath): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($localPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Cannot open export archive "%s".', $localPath));
        }

        foreach ($this->exporters as $exporter) {
            $added = $zip->addFromString(
                $exporter->filename(),
                json_encode($exporter->export($user), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            );
            if (!$added) {
                throw new \RuntimeException(sprintf('Cannot write "%s" into export archive "%s".', $exporter->filename(), $localPath));
            }
        }

        // close() can fail after every addFromString() succeeded (e.g. the
        // volume fills up while ZipArchive flushes its central directory) —
        // a Ready export must never point at a truncated ZIP.
        if (!$zip->close()) {
            throw new \RuntimeException(sprintf('Cannot finalize export archive "%s".', $localPath));
        }
    }

    /**
     * Uploads under a temporary key, then moves it into place, so the final
     * key only ever flips whole: a redelivered message rebuilds a Ready
     * export's archive, and a crash mid-upload must never leave a truncated
     * ZIP behind a still-valid download link. The move is a rename() on local
     * storage and a server-side copy on S3 — neither streams the archive back
     * through the app.
     */
    private function upload(string $localPath, string $key): void
    {
        $tmpKey = $key.'.tmp';

        $stream = fopen($localPath, 'r');
        if (false === $stream) {
            throw new \RuntimeException(sprintf('Cannot read the built export archive at "%s".', $localPath));
        }

        try {
            $this->exportStorage->writeStream($tmpKey, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            $this->exportStorage->move($tmpKey, $key);
        } catch (\Throwable $e) {
            // Nothing else ever looks at a `.tmp` key — both purgers only know
            // `<id>.zip` — so one left behind is orphaned in the bucket
            // forever.
            try {
                $this->exportStorage->delete($tmpKey);
            } catch (FilesystemException) {
                // The move already failed; a failing cleanup on top of it must
                // not mask the original error.
            }

            throw $e;
        }
    }
}
