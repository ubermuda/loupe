<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Module\OAuth\ClientMetadata\ClientIdUrl;
use App\Module\OAuth\ClientMetadata\TrustedClientIds;
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
        private TrustedClientIds $trustedClientIds,
    ) {
    }

    public function __invoke(ShowConsentCommand $command): ConsentView
    {
        $client = $command->authorizationRequest->getClient();
        $registered = (array) $client->getRedirectUri();
        $redirectUri = $command->authorizationRequest->getRedirectUri() ?? (string) ($registered[0] ?? '');

        $document = $this->clientMetadataDocuments->find($client->getIdentifier());
        $clientIdUrl = null === $document ? null : ClientIdUrl::parse($document->url);
        $trusted = null !== $clientIdUrl && $this->trustedClientIds->isTrusted($clientIdUrl);

        $allProjects = false;
        foreach ($command->authorizationRequest->getScopes() as $scope) {
            $allProjects = $allProjects || GrantedScope::ALL_PROJECTS === $scope->getIdentifier();
        }

        return new ConsentView(
            clientName: $client->getName(),
            clientHost: $clientIdUrl?->host,
            clientIdUrl: $clientIdUrl?->url,
            clientIdPath: $clientIdUrl?->pathDisplay(),
            clientTrusted: $trusted,
            clientIconUrl: $trusted && null !== $document?->iconType ? $this->urls->generate('oauth2_client_icon', ['identifier' => $document->clientIdentifier]) : null,
            scopes: $command->scopes,
            needsProject: !$allProjects && GrantedScope::anyNeedsProject($command->scopes),
            allProjects: $allProjects,
            redirectOrigin: RedirectUris::origin($redirectUri),
            loopbackOnly: RedirectUris::allLoopback($registered),
            projects: array_values($this->projects->findByOwner($command->user)),
        );
    }
}
