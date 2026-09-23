<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\Project\Entity\Project;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/** Claims the repository a verified delivery names for one project, then announces what the delivery says. */
final readonly class DeliveryAnnouncer
{
    public function __construct(
        private ForgeRepositories $forgeRepositories,
        private EventDispatcherInterface $events,
        private LoggerInterface $logger,
    ) {
    }

    public function announce(Project $project, GitHubRepositoryRef $repository, GitHubDelivery $delivery): void
    {
        $claim = $this->forgeRepositories->claim($project, GitHubDelivery::FORGE, $repository->externalId(), $repository->fullName);
        if (ForgeClaimOutcome::Refused === $claim->outcome || null === $claim->repository) {
            $this->logger->info('github.delivery_dropped', [
                'projectId' => (string) $project->id,
                'repositoryId' => $repository->id,
                'reason' => 'owned_elsewhere',
            ]);

            return;
        }

        $this->forgeRepositories->accepted($claim->repository, new \DateTimeImmutable());

        $deliveries = $delivery->forgeDeliveries();
        // First, so a pull request fact in the same delivery joins on the new path.
        if (null !== $claim->movedFrom) {
            array_unshift($deliveries, new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, GitHubDelivery::FORGE, $claim->movedFrom, movedTo: $repository->fullName));
        }

        if ([] !== $deliveries) {
            $this->events->dispatch(new ForgeDeliveryReceived($project->id ?? throw new \LogicException('A claimed project has an id.'), $deliveries));
        }
    }
}
