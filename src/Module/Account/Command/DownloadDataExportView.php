<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

final readonly class DownloadDataExportView
{
    /** @param resource $stream an open read handle the caller must close */
    public function __construct(
        public mixed $stream,
        public string $fileName,
    ) {
    }
}
