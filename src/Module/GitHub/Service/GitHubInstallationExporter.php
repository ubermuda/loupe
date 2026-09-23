<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\GitHub\Repository\GitHubInstallationRepository;

final readonly class GitHubInstallationExporter implements UserDataExporterInterface
{
    public function __construct(
        private GitHubInstallationRepository $gitHubInstallations,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'github_installations.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->gitHubInstallations->findByOwner($user) as $installation) {
            yield [
                'project' => $installation->project->name,
                'installationId' => $installation->installationId,
                'accountLogin' => $installation->accountLogin,
                'repositorySelection' => $installation->repositorySelection->value,
                'suspendedAt' => $installation->suspendedAt?->format(\DateTimeInterface::ATOM),
                'removedAt' => $installation->removedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $installation->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
