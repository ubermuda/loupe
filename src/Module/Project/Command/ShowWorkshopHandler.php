<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Project\Stats\ProjectStats;
use App\Module\Project\Stats\ProjectStatsProviderInterface;
use App\Module\Project\Workshop\WorkshopAttentionProviderInterface;
use App\Module\Project\Workshop\WorkshopCardsProviderInterface;
use App\Outbox\Command\ListActivityCommand;
use App\Outbox\Command\ListActivityHandler;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ShowWorkshopHandler
{
    /** @param iterable<ProjectStatsProviderInterface> $statsProviders */
    public function __construct(
        #[AutowireIterator('app.project_stats_provider')]
        private iterable $statsProviders,
        private WorkshopAttentionProviderInterface $attention,
        private WorkshopCardsProviderInterface $cards,
        private ListActivityHandler $activity,
    ) {
    }

    public function __invoke(ShowWorkshopCommand $command): ShowWorkshopView
    {
        $stats = new ProjectStats();
        $projectId = (string) ($command->project->id ?? throw new \LogicException('Project has no id.'));

        foreach ($this->statsProviders as $provider) {
            $contribution = $provider->statsFor([$command->project])[$projectId] ?? null;
            if (null !== $contribution) {
                $stats = $stats->merge($contribution);
            }
        }

        return new ShowWorkshopView(
            $command->project,
            $stats,
            $this->attention->forProject($command->project),
            $this->cards->forProject($command->project),
            ($this->activity)(new ListActivityCommand($command->project, 4))->entries,
        );
    }
}
