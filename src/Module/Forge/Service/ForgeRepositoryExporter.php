<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Forge\Repository\ForgeRepositoryRepository;

final readonly class ForgeRepositoryExporter implements UserDataExporterInterface
{
    public function __construct(
        private ForgeRepositoryRepository $forgeRepositories,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'forge_repositories.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->forgeRepositories->findByOwner($user) as $repository) {
            yield [
                'project' => $repository->project->name,
                'forge' => $repository->forge,
                'externalId' => $repository->externalId,
                'path' => $repository->path,
                'lastAcceptedAt' => $repository->lastAcceptedAt?->format(\DateTimeInterface::ATOM),
                'createdAt' => $repository->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
