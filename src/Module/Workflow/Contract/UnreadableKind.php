<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum UnreadableKind: string
{
    case Failed = 'failed';
    case Off = 'off';
    case MissingCondition = 'missing-condition';
}
