<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What a person or Loupe asks a bridge to do with a worker run. */
enum BridgeCommandKind: string
{
    case ResumeRun = 'resume-run';

    case StopRun = 'stop-run';

    /** The bridge runs the command of a failed command run again, as a new run that continues it. */
    case RerunCommand = 'rerun-command';

    /** The bridge that holds the transcript of an interactive session reports the usage of one run. */
    case CollectSessionUsage = 'collect-session-usage';
}
