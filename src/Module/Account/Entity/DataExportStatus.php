<?php

declare(strict_types=1);

namespace App\Module\Account\Entity;

enum DataExportStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
}
