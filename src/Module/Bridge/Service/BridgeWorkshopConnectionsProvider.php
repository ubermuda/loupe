<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Bridge\Command\ListAgentsCommand;
use App\Module\Bridge\Command\ListAgentsHandler;
use App\Module\Project\Entity\Project;
use App\Module\Project\Workshop\WorkshopConnection;
use App\Module\Project\Workshop\WorkshopConnectionsProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsAlias(WorkshopConnectionsProviderInterface::class)]
final readonly class BridgeWorkshopConnectionsProvider implements WorkshopConnectionsProviderInterface
{
    public function __construct(
        private ListAgentsHandler $agents,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[\Override]
    public function forProject(Project $project): array
    {
        $url = $this->urls->generate('app_project_agents', ['id' => (string) $project->id]);
        $connections = [];
        foreach (($this->agents)(new ListAgentsCommand($project))->connections as $connection) {
            $id = (string) $connection->bridge->id;
            $connections[] = new WorkshopConnection(
                $id,
                $connection->bridge->cliVersion,
                $connection->status->quiet,
                $url.'#agent-connection-'.$id,
            );
        }

        return $connections;
    }
}
