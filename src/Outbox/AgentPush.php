<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Module\Bridge\BridgeEventType;
use App\Module\Project\ProjectEventType;

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

    /** The only event types a bridge acts on. The drain pushes these alone, and the replay serves these alone. */
    public const array BRIDGE_TYPES = [
        BridgeEventType::WORK_REQUEST,
        BridgeEventType::COMMAND,
        BridgeEventType::CARD_HELD,
        BridgeEventType::CARD_RELEASED,
        ProjectEventType::RENAMED,
    ];

    private function __construct()
    {
    }
}
