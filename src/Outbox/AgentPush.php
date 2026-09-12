<?php

declare(strict_types=1);

namespace App\Outbox;

/**
 * Live push of outbox events to a waiting agent, over Mercure.
 *
 * Everything reachable only through the hub hangs off this one flag: publishing
 * an event, draining the outbox, and issuing the subscriber credentials the
 * bridge CLI uses. A producer's own features stay available with this off.
 *
 * The flag also carries an environment prerequisite, so an instance with no
 * configured hub reads it as off whatever is stored.
 */
final class AgentPush
{
    public const string FLAG = 'agent.push.enabled';

    private function __construct()
    {
    }
}
