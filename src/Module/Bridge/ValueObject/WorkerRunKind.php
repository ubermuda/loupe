<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Who opened a run: the bridge started a worker or a command, or a person opened an interactive session. */
enum WorkerRunKind: string
{
    case Worker = 'worker';

    /** A Claude Code session that a person runs on a card. A bridge can launch it, but never holds it. */
    case Interactive = 'interactive';

    /** A command that a bridge rule runs with no agent, so the run has no session and no cost. */
    case Command = 'command';
}
