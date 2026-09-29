<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\ExperimentPinRepository;

final readonly class ExperimentPinExporter implements UserDataExporterInterface
{
    public function __construct(
        private ExperimentPinRepository $experimentPins,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'experiment_pins.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->experimentPins->findByOwner($user) as $pin) {
            yield [
                'project' => $pin->project->name,
                'cardId' => (string) $pin->cardId,
                'experiment' => $pin->experiment,
                'variant' => $pin->variant,
                'createdAt' => $pin->createdAt->format(\DateTimeInterface::ATOM),
                'updatedAt' => $pin->updatedAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
