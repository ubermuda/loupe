<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ListConnectedAppsHandler
{
    public function __construct(
        private GrantRepository $grants,
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ListConnectedAppsCommand $command): ListConnectedAppsView
    {
        $userId = $command->user->id ?? throw new \LogicException('a persisted user always has an id');

        $apps = [];
        foreach ($this->grants->findLiveGrantsForUser($userId) as $row) {
            $granted = GrantedScope::fromScopes(explode(' ', $row['scopes']));
            if (null === $granted) {
                continue;
            }

            $apps[$row['clientId']]['name'] = $row['clientName'];
            $apps[$row['clientId']]['grants'][] = [
                'scopes' => $granted->scopes,
                'allProjects' => $granted->allProjects,
                'project' => null === $granted->projectId ? null : $this->projects->find($granted->projectId),
            ];
        }

        $connected = [];
        foreach ($apps as $clientId => $app) {
            $connected[] = new ConnectedApp((string) $clientId, $app['name'], $app['grants']);
        }

        return new ListConnectedAppsView($connected);
    }
}
