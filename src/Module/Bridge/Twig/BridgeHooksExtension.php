<?php

declare(strict_types=1);

namespace App\Module\Bridge\Twig;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\View\AgentConnection;
use App\Module\Project\Entity\Project;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The Rules page lists the hooks each bridge of the project runs through this
 * function, because no module may import Board.
 */
final class BridgeHooksExtension extends AbstractExtension
{
    public function __construct(
        private readonly ListAgentsHandler $agents,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bridge_hooks', $this->bridgeHooks(...)),
        ];
    }

    /** @return list<Bridge> the owner's bridges that follow the project, the latest heartbeat first */
    public function bridgeHooks(Project $project): array
    {
        return array_map(
            static fn (AgentConnection $connection): Bridge => $connection->bridge,
            ($this->agents)(new ListAgentsCommand($project))->connections,
        );
    }
}
