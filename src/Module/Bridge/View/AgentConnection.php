<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Bridge\Entity\Bridge;

final readonly class AgentConnection
{
    public function __construct(
        public Bridge $bridge,
        public BridgeStatus $status,
    ) {
    }
}
