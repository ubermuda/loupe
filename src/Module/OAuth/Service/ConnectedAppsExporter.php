<?php

declare(strict_types=1);

namespace App\Module\OAuth\Service;

use App\Module\Account\Entity\User;
use App\Module\Account\Export\UserDataExporterInterface;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Scope\GrantedScope;

/** The apps that hold a live grant for the user. Token values never leave the database. */
final readonly class ConnectedAppsExporter implements UserDataExporterInterface
{
    public function __construct(
        private GrantRepository $grants,
    ) {
    }

    #[\Override]
    public function filename(): string
    {
        return 'connected_apps.json';
    }

    #[\Override]
    public function export(User $user): iterable
    {
        $userId = $user->id ?? throw new \LogicException('a persisted user always has an id');

        foreach ($this->grants->findLiveGrantsForUser($userId) as $row) {
            $granted = GrantedScope::fromScopes(explode(' ', $row['scopes']));

            yield [
                'clientId' => $row['clientId'],
                'clientName' => $row['clientName'],
                'scope' => $granted?->scope->value,
                'projectId' => $granted?->projectId?->toRfc4122(),
            ];
        }
    }
}
