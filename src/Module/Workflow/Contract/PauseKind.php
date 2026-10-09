<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** What paused a card: a rule that pauses, too many retries, work that hit its limit or its timeout, or a worker that stopped. */
enum PauseKind: string
{
    case Rule = 'rule';

    case Retries = 'retries';

    case WorkLimit = 'work-limit';

    case WorkTimeout = 'work-timeout';

    /** The worker stopped with a code that the template does not retry, so a person must look. */
    case WorkStopped = 'work-stopped';
}
