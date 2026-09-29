<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** Who started a run. A run with no trigger is one the bridge started on its own. */
enum WorkerRunTrigger: string
{
    /** A person resumed the run from the web UI. */
    case Person = 'person';
}
