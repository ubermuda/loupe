<?php

declare(strict_types=1);

namespace App\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\OAuth\Scope\GrantedScope;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Security\ProjectVoter;
use League\Bundle\OAuth2ServerBundle\Converter\UserConverterInterface;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Completes an authorization request the user approved or denied on the
 * consent page. An approval binds the grant to one project the user owns by
 * adding its project:<uuid> scope; a denial throws the access_denied error
 * that redirects back to the client.
 */
final readonly class ResolveAuthorizationHandler
{
    public function __construct(
        #[Autowire(service: 'league.oauth2_server.authorization_server')]
        private AuthorizationServer $server,

        #[Autowire(service: 'league.oauth2_server.repository.scope')]
        private ScopeRepositoryInterface $scopes,

        #[Autowire(service: 'league.oauth2_server.converter.user')]
        private UserConverterInterface $userConverter,

        #[Autowire(service: 'league.oauth2_server.factory.psr17')]
        private ResponseFactoryInterface $psrResponses,
        private ProjectRepository $projects,
        private Security $security,
        private Auditor $auditor,
    ) {
    }

    /** @throws OAuthServerException when the user denied the request */
    public function __invoke(ResolveAuthorizationCommand $command): ResponseInterface
    {
        $request = $command->authorizationRequest;
        $request->setUser($this->userConverter->toLeague($command->user));
        $request->setAuthorizationApproved($command->approved);

        $project = null;
        if ($command->approved && GrantedScope::needsProject($command->scope)) {
            $project = $this->ownedProject($command->projectId);
            $projectScope = $this->scopes->getScopeEntityByIdentifier(GrantedScope::projectScope($project->id ?? throw new \LogicException('a persisted project always has an id')))
                ?? throw new \LogicException('an existing project always resolves to a scope');
            $request->setScopes([...$request->getScopes(), $projectScope]);
        }

        $response = $this->server->completeAuthorizationRequest($request, $this->psrResponses->createResponse());

        $clientId = $request->getClient()->getIdentifier();
        $this->auditor->record(
            'oauth.client_authorized',
            AuditOutcome::Success,
            ['clientId' => $clientId, 'scope' => $command->scope->value, 'projectId' => null === $project ? null : (string) $project->id],
            new AuditSubject('oauth_client', $clientId),
        );

        return $response;
    }

    private function ownedProject(?string $projectId): Project
    {
        if (null === $projectId || '' === $projectId) {
            throw new DomainErrors(['project' => 'oauth.consent.error.project_required']);
        }

        $project = Uuid::isValid($projectId) ? $this->projects->find(Uuid::fromString($projectId)) : null;
        if (null === $project || !$this->security->isGranted(ProjectVoter::MANAGE, $project)) {
            throw new DomainErrors(['project' => 'oauth.consent.error.project_not_owned']);
        }

        return $project;
    }
}
