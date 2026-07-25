<?php

declare(strict_types=1);

namespace App\Module\Project\Service;

use App\DataExport\UserDataExporterInterface;
use App\Module\Account\Entity\User;
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
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->projects->findByOwner($user) as $project) {
            $rows[] = [
                'id' => (string) $project->id,
                'name' => $project->name,
                'domain' => $project->domain,
                'createdAt' => $project->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
