<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** What happened to a card, as one row of its history records it. */
enum CardEventKind: string
{
    case Created = 'created';
    case Moved = 'moved';
    case FixRequested = 'fix-requested';
    case Stopped = 'stopped';
    case ReadyToMerge = 'ready-to-merge';
    case RunFinished = 'run-finished';
}
