<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use App\Module\Forge\ForgeDelivery;
use App\Module\Forge\ForgeEventType;
use App\Module\Forge\Service\ForgeClaim;
use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\Forge\Service\PullRequestTracker;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\Project\Entity\Project;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/** Claims the repository a delivery names for one project, then announces what the delivery says and queues the refreshes it hints. */
final readonly class DeliveryAnnouncer
{
    public function __construct(
        private ForgeRepositories $forgeRepositories,
        private EventDispatcherInterface $events,
        private PullRequestTracker $tracker,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Claims the repository, and announces a move when the claim found this
     * project's row under an older path. Answers null when the claim is refused.
     */
    public function claim(Project $project, GitHubRepositoryRef $repository, ForgeRepositorySource $source, ?int $installationId = null): ?ForgeClaim
    {
        $claim = $this->forgeRepositories->claim($project, GitHubDelivery::FORGE, $repository->externalId(), $repository->fullName, $source, null === $installationId ? null : (string) $installationId);
        if (ForgeClaimOutcome::Refused === $claim->outcome || null === $claim->repository) {
            $this->logger->info('github.repository_refused', [
                'projectId' => (string) $project->id,
                'repositoryId' => $repository->id,
                'installationId' => $installationId,
            ]);

            return null;
        }

        if (null !== $claim->movedFrom) {
            $this->dispatch($project, [new ForgeDelivery(ForgeEventType::REPOSITORY_MOVED, GitHubDelivery::FORGE, $claim->movedFrom, movedTo: $repository->fullName)], $source);
        }

        return $claim;
    }

    public function announce(Project $project, GitHubRepositoryRef $repository, GitHubDelivery $delivery, ForgeRepositorySource $source, ?int $installationId = null): void
    {
        $claim = $this->claim($project, $repository, $source, $installationId);
        if (null === $claim?->repository) {
            return;
        }

        $this->forgeRepositories->accepted($claim->repository, new \DateTimeImmutable());

        $deliveries = $delivery->forgeDeliveries();
        if ([] !== $deliveries) {
            $this->dispatch($project, $deliveries, $source);
        }

        // A hook repository gets the bare events alone, so its review verdict never reaches the automation.
        $verdicts = ForgeRepositorySource::Installation === $source;
        foreach ($delivery->refreshHints() as $hint) {
            match (true) {
                null !== $hint->number => $this->tracker->refresh($project, GitHubDelivery::FORGE, $repository->fullName, $hint->number, $verdicts ? $hint->verdict : null, $verdicts ? $hint->reviewId : null),
                null !== $hint->headSha => $this->tracker->refreshHead($project, GitHubDelivery::FORGE, $repository->fullName, $hint->headSha),
                default => $this->tracker->refreshBase($project, GitHubDelivery::FORGE, $repository->fullName, $hint->baseBranch ?? throw new \LogicException('A hint sets one field.')),
            };
        }
    }

    /** @param non-empty-list<ForgeDelivery> $deliveries */
    private function dispatch(Project $project, array $deliveries, ForgeRepositorySource $source): void
    {
        $this->events->dispatch(new ForgeDeliveryReceived($project->id ?? throw new \LogicException('A claimed project has an id.'), $deliveries, $source));
    }
}
