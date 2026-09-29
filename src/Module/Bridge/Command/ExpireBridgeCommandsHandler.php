<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeCommandRepository;
use Psr\Clock\ClockInterface;

/** Answers how many commands expired. */
final readonly class ExpireBridgeCommandsHandler
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireBridgeCommandsCommand $command): int
    {
        return $this->bridgeCommands->expireDue($this->clock->now());
    }
}
