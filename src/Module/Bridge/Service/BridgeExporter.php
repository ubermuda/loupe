<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Bridge\Repository\BridgeRepository;

final readonly class BridgeExporter implements UserDataExporterInterface
{
    public function __construct(
        private BridgeRepository $bridges,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'bridges.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        foreach ($this->bridges->findByOwner($user) as $bridge) {
            yield [
                'bridgeId' => (string) $bridge->id,
                'projects' => $bridge->projects,
                'cliVersion' => $bridge->cliVersion,
                'lastSeenAt' => $bridge->lastSeenAt->format(\DateTimeInterface::ATOM),
            ];
        }
    }
}
