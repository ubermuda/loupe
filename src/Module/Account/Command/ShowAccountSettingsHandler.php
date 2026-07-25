<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Repository\DataExportRepository;

final readonly class ShowAccountSettingsHandler
{
    public function __construct(private DataExportRepository $dataExports)
    {
    }

    public function __invoke(ShowAccountSettingsCommand $command): ShowAccountSettingsView
    {
        return new ShowAccountSettingsView(
            user: $command->user,
            exports: $this->dataExports->findByUser($command->user),
        );
    }
}
