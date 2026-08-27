<?php

declare(strict_types=1);

namespace App\Module\Account\Export;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use League\Flysystem\Config;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Uid\Uuid;

readonly class DataExportArchiveBuilder
{
    private const int JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES;

    public function __construct(
        /** @var iterable<UserDataExporterInterface> */
        #[AutowireIterator('app.user_data_exporter')]
        private iterable $exporters,

        #[Target('export.storage')]
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

        // Each payload goes to a temp file and is added by path, not by string:
        // addFromString() holds its data until close(), so every exporter's JSON
        // was resident at once. Rows are streamed into that file one at a time,
        // so no payload is ever whole in memory either.
        $payloadPaths = [];

        try {
            foreach ($this->exporters as $exporter) {
                $payloadPath = tempnam(sys_get_temp_dir(), 'loupe-export-payload-');
                if (false === $payloadPath) {
                    throw new \RuntimeException('Cannot create a temporary file for an export payload.');
                }
                $payloadPaths[] = $payloadPath;

                $this->writePayload($payloadPath, $exporter, $user);

                if (!$zip->addFile($payloadPath, $exporter->filename())) {
                    throw new \RuntimeException(sprintf('Cannot write "%s" into export archive "%s".', $exporter->filename(), $localPath));
                }
            }

            // close() can fail after every add succeeded (e.g. the volume fills
            // up while ZipArchive flushes its central directory) — a Ready
            // export must never point at a truncated ZIP.
            if (!$zip->close()) {
                throw new \RuntimeException(sprintf('Cannot finalize export archive "%s".', $localPath));
            }
        } finally {
            // Only after close(): addFile() defers the read, so deleting a
            // payload before the archive is finalized empties its entry.
            foreach ($payloadPaths as $payloadPath) {
                @unlink($payloadPath);
            }
        }
    }

    /**
     * Writes an exporter's rows as one pretty-printed JSON document, encoding
     * and flushing each row on its own. The bytes match a single json_encode of
     * the whole payload: pretty-print indents purely by nesting depth, so a row
     * encoded alone and shifted four spaces sits exactly where it would have.
     */
    private function writePayload(string $path, UserDataExporterInterface $exporter, User $user): void
    {
        $handle = fopen($path, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot write the "%s" export payload.', $exporter->filename()));
        }

        try {
            $container = null;
            foreach ($exporter->export($user) as $key => $row) {
                $encoded = json_encode($row, self::JSON_FLAGS);

                if (null === $container) {
                    // The first key decides the shape, so a row-shaped payload
                    // opens an array and a field-shaped one an object.
                    $container = \is_int($key) ? ['[', ']'] : ['{', '}'];
                    $this->writeChunk($handle, $container[0]."\n", $exporter);
                } else {
                    $this->writeChunk($handle, ",\n", $exporter);
                }

                $shifted = str_replace("\n", "\n    ", $encoded);
                $this->writeChunk(
                    $handle,
                    '{' === $container[0]
                        ? '    '.json_encode((string) $key, self::JSON_FLAGS).': '.$shifted
                        : '    '.$shifted,
                    $exporter,
                );
            }

            // An exporter with nothing to say writes an empty array whatever
            // shape it would otherwise have had.
            $this->writeChunk($handle, null === $container ? '[]' : "\n".$container[1], $exporter);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private function writeChunk(mixed $handle, string $chunk, UserDataExporterInterface $exporter): void
    {
        if (strlen($chunk) !== fwrite($handle, $chunk)) {
            throw new \RuntimeException(sprintf('Cannot write the "%s" export payload.', $exporter->filename()));
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

        try {
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

            // Stated rather than carried over: Flysystem otherwise reads the
            // source object's ACL, and stores implementing none — Garage among
            // them — answer GetObjectAcl with a 501 that surfaces here as an
            // unexplained "unable to move file".
            $this->exportStorage->move($tmpKey, $key, [
                Config::OPTION_RETAIN_VISIBILITY => false,
                Config::OPTION_VISIBILITY => Visibility::PRIVATE,
            ]);
        } catch (\Throwable $e) {
            // Covers a half-written upload as well as a failed move: nothing
            // else ever looks at a `.tmp` key — every purger only knows
            // `<id>.zip` — so one left behind is orphaned forever.
            try {
                $this->exportStorage->delete($tmpKey);
            } catch (FilesystemException) {
                // The upload already failed; a failing cleanup on top of it
                // must not mask the original error.
            }

            throw $e;
        }
    }
}
