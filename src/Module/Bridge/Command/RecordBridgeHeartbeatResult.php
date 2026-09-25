<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\Bridge;

/** The recorded bridge, and the CLI range the server answers it with. */
final readonly class RecordBridgeHeartbeatResult
{
    public function __construct(
        public Bridge $bridge,
        public string $cliRange,
    ) {
    }
}
