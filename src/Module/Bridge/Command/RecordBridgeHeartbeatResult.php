<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;

/**
 * The recorded bridge, the CLI range the server answers it with, and the
 * commands the bridge has still to act on, oldest first.
 */
final readonly class RecordBridgeHeartbeatResult
{
    /** @param list<BridgeCommand> $commands */
    public function __construct(
        public Bridge $bridge,
        public string $cliRange,
        public array $commands,
    ) {
    }
}
