<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;

/** Finds the App installation that acts on a pull request for its project. */
final readonly class GitHubPullRequestInstallations
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
        private GitHubInstallationRepository $gitHubInstallations,
    ) {
    }

    /**
     * @return array{int, string} the installation id, and the repository path as the forge spells it
     *
     * @throws GitHubInstallationUnavailable
     */
    public function for(ForgePullRequest $pullRequest): array
    {
        $row = $this->forgeRepositories->findInstallationRowByPath($pullRequest->project, GitHubDelivery::FORGE, $pullRequest->repository);
        $sourceRef = $row?->sourceRef;
        if (null === $row || null === $sourceRef || !ctype_digit($sourceRef)) {
            throw new GitHubInstallationUnavailable('no_installation');
        }

        $installation = $this->gitHubInstallations->findOneByInstallationId((int) $sourceRef);
        if (null === $installation || null !== $installation->removedAt || !$installation->project->id?->equals($pullRequest->project->id)) {
            throw new GitHubInstallationUnavailable('no_installation');
        }
        if (null !== $installation->suspendedAt) {
            throw new GitHubInstallationUnavailable('installation_suspended');
        }

        return [$installation->installationId, $row->path];
    }

    /** The REST path of a repository, such as `/repos/owner/name`. */
    public static function repositoryPath(string $path): string
    {
        return '/repos/'.implode('/', array_map(rawurlencode(...), explode('/', $path, 2)));
    }
}
