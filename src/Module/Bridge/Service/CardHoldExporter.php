<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\CardHoldRepository;

final readonly class CardHoldExporter implements UserDataExporterInterface
{
    public function __construct(
        private CardHoldRepository $cardHolds,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridge_card_holds.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->cardHolds->findByOwner($user) as $hold) {
            yield [
                'project' => $hold->project->name,
                'cardId' => (string) $hold->cardId,
                'heldAt' => $hold->heldAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
