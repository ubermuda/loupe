<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\GitHubRepositoryRef;
use App\Module\GitHub\InvalidGitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Service\DeliveryAnnouncer;
use App\Module\GitHub\Service\RefusedDeliveries;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every installation of the App signs with one secret, so the installation id
 * in the body decides which project a delivery belongs to. A delivery with no
 * installation, or for one Loupe does not know, is dropped.
 */
final readonly class ReceiveAppDeliveryHandler
{
    public function __construct(
        #[Autowire(env: 'GITHUB_APP_WEBHOOK_SECRET')]
        private string $appSecret,
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositories $forgeRepositories,
        private DeliveryAnnouncer $announcer,
        private RefusedDeliveries $refusals,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReceiveAppDeliveryCommand $command): GitHubDeliveryOutcome
    {
        try {
            $delivery = GitHubDelivery::fromRequest($command->request, $this->appSecret);
        } catch (InvalidGitHubDelivery $e) {
            $this->refusals->record($e);

            return GitHubDeliveryOutcome::Refused;
        }

        $installationId = $delivery->installationId();
        $installation = null === $installationId ? null : $this->gitHubInstallations->findOneByInstallationId($installationId);
        if (null === $installation || null !== $installation->removedAt) {
            $this->dropped($delivery, null === $installationId ? 'no_installation' : 'unknown_installation');

            return GitHubDeliveryOutcome::Received;
        }

        match ($delivery->event) {
            'installation' => $this->installationChanged($installation, $delivery),
            'installation_repositories' => $this->repositoriesChanged($installation, $delivery),
            default => $this->owned($installation, $delivery),
        };

        return GitHubDeliveryOutcome::Received;
    }

    private function installationChanged(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        switch ($delivery->action()) {
            case 'suspend':
                $installation->suspendedAt = new \DateTimeImmutable();
                break;
            case 'unsuspend':
                $installation->suspendedAt = null;
                break;
            case 'deleted':
                $installation->removedAt = new \DateTimeImmutable();
                foreach ($delivery->repositories('repositories') as $repository) {
                    $this->forgeRepositories->release($installation->project, GitHubDelivery::FORGE, $repository->externalId());
                }
                break;
            case 'created':
                $this->claimAll($installation, $delivery->repositories('repositories'));
                break;
        }

        $this->em->flush();
    }

    private function repositoriesChanged(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $this->claimAll($installation, $delivery->repositories('repositories_added'));
        foreach ($delivery->repositories('repositories_removed') as $repository) {
            $this->forgeRepositories->release($installation->project, GitHubDelivery::FORGE, $repository->externalId());
        }

        $selection = $delivery->repositorySelection();
        if (null !== $selection) {
            $installation->repositorySelection = $selection;
        }

        $this->em->flush();
    }

    /** @param list<GitHubRepositoryRef> $repositories */
    private function claimAll(GitHubInstallation $installation, array $repositories): void
    {
        foreach ($repositories as $repository) {
            $claim = $this->forgeRepositories->claim($installation->project, GitHubDelivery::FORGE, $repository->externalId(), $repository->fullName);
            if (ForgeClaimOutcome::Refused === $claim->outcome) {
                $this->logger->info('github.repository_refused', [
                    'installationId' => $installation->installationId,
                    'repositoryId' => $repository->id,
                ]);
            }
        }
    }

    /**
     * An installation that reaches every repository claims one on its first
     * delivery. One with a selection claims only through the events that name
     * the selection, so a repository outside it is dropped here.
     */
    private function owned(GitHubInstallation $installation, GitHubDelivery $delivery): void
    {
        $repository = $delivery->repository();
        if (null !== $installation->suspendedAt || null === $repository) {
            $this->dropped($delivery, null === $repository ? 'no_repository' : 'suspended');

            return;
        }

        $unclaimed = null === $this->forgeRepositories->ownerOf(GitHubDelivery::FORGE, $repository->externalId());
        if ($unclaimed && GitHubRepositorySelection::Selected === $installation->repositorySelection) {
            $this->dropped($delivery, 'not_selected');

            return;
        }

        $this->announcer->announce($installation->project, $repository, $delivery);
    }

    private function dropped(GitHubDelivery $delivery, string $reason): void
    {
        $this->logger->info('github.delivery_dropped', [
            'event' => $delivery->event,
            'installationId' => $delivery->installationId(),
            'reason' => $reason,
        ]);
    }
}
