<?php

declare(strict_types=1);

namespace App\Module\Bridge\ValueObject;

/** What a tool call does, as the bridge tags it for each harness. */
enum WorkerRunToolCallKind: string
{
    case Shell = 'shell';

    /** The call starts a sub-agent and waits for it. */
    case Subagent = 'subagent';

    case Tool = 'tool';
}
