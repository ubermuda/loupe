<?php

declare(strict_types=1);

namespace App\Outbox;

/**
 * Live push of outbox events to a waiting agent, over Mercure.
 *
 * Draining the outbox to the hub and issuing the subscriber credentials the
 * bridge CLI uses hang off this flag. Live updates in the browser have their
 * own, App\Mercure\LiveUpdates. A producer's own features stay available with
 * this off.
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
