<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum ActionOutcomeKind: string
{
    case Done = 'done';
    case Refused = 'refused';
    case Pause = 'pause';
}
