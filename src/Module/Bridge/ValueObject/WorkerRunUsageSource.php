<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Where the token counts of a run come from. A run with no source has unknown usage. */
enum WorkerRunUsageSource: string
{
    /** Claude Code printed the counts in its result. */
    case Reported = 'reported';

    /** The bridge counted them from the session transcript. */
    case Estimated = 'estimated';
}
