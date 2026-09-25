<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Who opened a run: the bridge started a worker, or a person opened an interactive session. */
enum WorkerRunKind: string
{
    case Worker = 'worker';

    /** A Claude Code session that a person runs on a card. No bridge holds it. */
    case Interactive = 'interactive';
}
