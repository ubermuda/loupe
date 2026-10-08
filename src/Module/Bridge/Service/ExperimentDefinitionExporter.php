<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\ExperimentDefinitionRepository;

final readonly class ExperimentDefinitionExporter implements UserDataExporterInterface
{
    public function __construct(
        private ExperimentDefinitionRepository $experimentDefinitions,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'experiment_definitions.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->experimentDefinitions->findByOwner($user) as $definition) {
            yield [
                'project' => $definition->project->name,
                'experiment' => $definition->experiment,
                'weights' => $definition->weights,
                'metrics' => $definition->metrics,
                'reportedAt' => $definition->reportedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
