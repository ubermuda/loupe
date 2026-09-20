<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Service\RedirectUris;
use App\Module\Project\Repository\ProjectRepository;

final readonly class ShowConsentHandler
{
    public function __construct(
        private ProjectRepository $projects,
    ) {
    }

    public function __invoke(ShowConsentCommand $command): ConsentView
    {
        $client = $command->authorizationRequest->getClient();
        $registered = (array) $client->getRedirectUri();
        $redirectUri = $command->authorizationRequest->getRedirectUri() ?? (string) ($registered[0] ?? '');

        return new ConsentView(
            clientName: $client->getName(),
            scope: $command->scope,
            needsProject: GrantedScope::needsProject($command->scope),
            redirectOrigin: RedirectUris::origin($redirectUri),
            loopbackOnly: RedirectUris::allLoopback($registered),
            projects: array_values($this->projects->findByOwner($command->user)),
        );
    }
}
