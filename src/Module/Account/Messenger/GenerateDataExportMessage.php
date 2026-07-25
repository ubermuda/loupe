<?php

declare(strict_types=1);

namespace App\Module\Account\Messenger;

final readonly class GenerateDataExportMessage
{
    public function __construct(public string $dataExportId)
    {
    }
}
