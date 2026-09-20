<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\Repository\ClientMetadataDocumentRepository;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\OAuth\Service\RedirectUris;
use App\Module\Project\Repository\ProjectRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ShowConsentHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private ClientMetadataDocumentRepository $clientMetadataDocuments,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(ShowConsentCommand $command): ConsentView
    {
        $client = $command->authorizationRequest->getClient();
        $registered = (array) $client->getRedirectUri();
        $redirectUri = $command->authorizationRequest->getRedirectUri() ?? (string) ($registered[0] ?? '');

        $document = $this->clientMetadataDocuments->find($client->getIdentifier());

        return new ConsentView(
            clientName: $client->getName(),
            clientHost: null === $document ? null : ClientIdUrl::parse($document->url)?->host,
            clientIconUrl: null === $document?->iconType ? null : $this->urls->generate('oauth2_client_icon', ['identifier' => $document->clientIdentifier]),
            scope: $command->scope,
            needsProject: GrantedScope::needsProject($command->scope),
            redirectOrigin: RedirectUris::origin($redirectUri),
            loopbackOnly: RedirectUris::allLoopback($registered),
            projects: array_values($this->projects->findByOwner($command->user)),
        );
    }
}
