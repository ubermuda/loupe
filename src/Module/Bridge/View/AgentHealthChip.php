<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;

/**
 * The health chip on a bridge card. A quiet bridge reads as stale whatever its pause.
 * Until the bridge reports the requested pause, the chip shows the change in progress.
 */
final readonly class AgentHealthChip
{
    public function __construct(
        public string $state,
        public string $label,
        public string $tone,
    ) {
    }

    public static function for(Bridge $bridge, BridgeStatus $status): self
    {
        $state = match (true) {
            $status->quiet => 'stale',
            $bridge->pauseRequested && true === $bridge->pausedReported => 'paused',
            $bridge->pauseRequested => 'pausing',
            true === $bridge->pausedReported => 'unpausing',
            default => 'healthy',
        };

        return new self($state, 'bridge.agents.status.'.$state, 'healthy' === $state ? 'ok' : 'pending');
    }
}
