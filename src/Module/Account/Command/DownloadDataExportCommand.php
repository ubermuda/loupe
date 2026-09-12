<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\DataExport;
use App\Module\Account\Entity\User;

final readonly class DownloadDataExportCommand
{
    public function __construct(
        public DataExport $export,
        public User $user,
        /** Raw token from the emailed link, or '' when the owner is signed in. */
        public string $token = '',
    ) {
    }
}
