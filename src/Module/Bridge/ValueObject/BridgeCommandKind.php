<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What a person asks a bridge to do with one of its worker runs. */
enum BridgeCommandKind: string
{
    case ResumeRun = 'resume-run';

    case StopRun = 'stop-run';
}
