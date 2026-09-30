<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeCommandRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use Psr\Clock\ClockInterface;

/** Answers how many commands expired. */
final readonly class ExpireBridgeCommandsHandler
{
    public function __construct(
        private BridgeCommandRepository $bridgeCommands,
        private WorkerRunChangedPublisher $runsChanged,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireBridgeCommandsCommand $command): int
    {
        // One instant for the read and the update, so the read names every project the update touches.
        $now = $this->clock->now();
        $projects = $this->bridgeCommands->findProjectsWithDue($now);
        $expired = $this->bridgeCommands->expireDue($now);

        foreach ($projects as $project) {
            $this->runsChanged->runsChanged($project);
        }

        return $expired;
    }
}
