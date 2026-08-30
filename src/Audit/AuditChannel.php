<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * Where a write came from. Typed on this side of the boundary only: the audit
 * package holds the channel as a plain string so a consuming application can
 * name its own.
 */
enum AuditChannel: string
{
    case Session = 'session';
    case Mcp = 'mcp';
    case Widget = 'widget';
    case Webhook = 'webhook';
    case Console = 'console';
    case Cron = 'cron';

    /** No request, no security token and nothing ambient — there is nothing left to attribute the write to. */
    case System = 'system';
}
