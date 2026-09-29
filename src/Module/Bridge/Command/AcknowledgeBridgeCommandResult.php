<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\BridgeCommand;

/**
 * The command as it is stored after the ack. A null command means the caller
 * holds no such command on that bridge. $settled is false when the command was
 * already settled and the ack changed nothing.
 */
final readonly class AcknowledgeBridgeCommandResult
{
    public function __construct(
        public ?BridgeCommand $command,
        public bool $settled,
    ) {
    }
}
