<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\Account\Repository\ConnectedAccountRepository;

final readonly class ConnectedAccountExporter implements UserDataExporterInterface
{
    public function __construct(
        private ConnectedAccountRepository $connectedAccounts,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'connected_accounts.json';
    }

    #[\Override]
    public function export(User $user): array
    {
        $rows = [];
        foreach ($this->connectedAccounts->findBy(['user' => $user]) as $account) {
            $rows[] = [
                'provider' => $account->provider->value,
                'providerUserId' => $account->providerUserId,
                'linkedAt' => $account->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }
}
