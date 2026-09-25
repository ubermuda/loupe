<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Repository\BridgeRepository;
use App\Module\Bridge\Service\BridgeLiveness;
use App\Module\Bridge\Service\CliCompatibility;
use App\Module\Bridge\View\AgentConnection;
use App\Module\Bridge\View\AgentUpdateChip;

final readonly class ListAgentsHandler
{
    public function __construct(
        private BridgeRepository $bridges,
        private BridgeLiveness $liveness,
        private CliCompatibility $compatibility,
    ) {
    }

    public function __invoke(ListAgentsCommand $command): ListAgentsView
    {
        $projectId = (string) $command->project->id;
        $bridges = array_values(array_filter(
            $this->bridges->findByOwner($command->project->owner),
            static fn ($bridge): bool => in_array($projectId, $bridge->projects, true),
        ));
        $statuses = $this->liveness->forOwner(
            $command->project->owner,
            array_map(static fn ($bridge) => $bridge->id, $bridges),
        );

        return new ListAgentsView(
            $command->project,
            array_map(
                fn ($bridge): AgentConnection => new AgentConnection(
                    $bridge,
                    $statuses[$bridge->id->toRfc4122()],
                    AgentUpdateChip::for($bridge, $this->compatibility->isCompatible($bridge->cliVersion)),
                ),
                $bridges,
            ),
        );
    }
}
