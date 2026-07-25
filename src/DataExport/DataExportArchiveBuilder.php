<?php

declare(strict_types=1);

namespace App\DataExport;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

final readonly class DataExportArchiveBuilder
{
    public function __construct(
        /** @var iterable<UserDataExporterInterface> */
        #[AutowireIterator('app.user_data_exporter')]
        private iterable $exporters,

        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /** Builds the archive and returns its absolute path. */
    public function build(User $user, Uuid $exportId): string
    {
        $path = DataExport::computeArchivePath($this->projectDir, $exportId);
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create export directory "%s".', $dir));
        }

        // Build to a temp file, then rename() atomically over the final path:
        // a redelivered message rebuilds a Ready export's archive, and a crash
        // mid-rebuild must never leave a corrupt ZIP behind a still-valid link.
        $tmpPath = $path.'.tmp';
        $zip = new \ZipArchive();
        if (true !== $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException(sprintf('Cannot open export archive "%s".', $tmpPath));
        }

        try {
            foreach ($this->exporters as $exporter) {
                $zip->addFromString(
                    $exporter->filename(),
                    json_encode($exporter->export($user), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
                );
            }
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($tmpPath);

            throw $e;
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);

            throw new \RuntimeException(sprintf('Cannot move export archive into place at "%s".', $path));
        }

        return $path;
    }
}
