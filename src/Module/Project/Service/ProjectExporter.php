<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ProjectExporter implements UserDataExporterInterface
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'projects.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->projects->findByOwner($user) as $project) {
            yield [
                'id' => (string) $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'searchLanguage' => $project->searchLanguage->value,
                'createdAt' => $project->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
