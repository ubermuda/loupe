<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

enum CardAutomationAction: string
{
    case FixRequested = 'fix-requested';
    case Stopped = 'stopped';
    case ReadyToMerge = 'ready-to-merge';
    case Synced = 'synced';
}
