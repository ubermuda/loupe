<?php

declare(strict_types=1);

namespace App\Module\Account\Messenger;

use App\Module\Account\Command\ProcessDataExportCommand;
use App\Module\Account\Command\ProcessDataExportHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GenerateDataExportHandler
{
    public function __construct(
        private ProcessDataExportHandler $processDataExport,
    ) {
    }

    public function __invoke(GenerateDataExportMessage $message): void
    {
        ($this->processDataExport)(new ProcessDataExportCommand($message->dataExportId));
    }
}
