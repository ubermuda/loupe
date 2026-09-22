<?php

declare(strict_types=1);

namespace App\Module\Project\Security;

use App\Module\Account\Entity\User;
use App\Module\OAuth\Scope\ApiScope;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Security\AuthenticatedCredential;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves the Project the current request is acting for.
 *
 * A credential bound to one project names that project. A credential that
 * covers every project of its owner takes the project from the request, in the
 * X-Loupe-Project header, and falls back to the owner's single project when it
 * has exactly one. A header that names a project the credential does not cover
 * is refused rather than ignored.
 */
#[WithMonologChannel('security')]
final readonly class AuthenticatedProjectResolver
{
    /** Names the project a request acts on, inside what the credential covers. */
    public const string PROJECT_HEADER = 'X-Loupe-Project';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private ProjectRepository $projects,
        private RequestStack $requests,
        private LoggerInterface $logger,
    ) {
    }

    /** The project whose site-review credential authenticated this request. */
    public function resolveWidgetProject(): ?Project
    {
        return $this->resolve($this->tokenStorage->getToken(), ApiScope::SiteReview);
    }

    /** The project whose MCP credential authenticated this request. */
    public function resolveMcpProject(): ?Project
    {
        return $this->mcpResolution()->project;
    }

    /** The project this MCP request acts on, or why it has none. */
    public function mcpResolution(): ProjectResolution
    {
        return $this->resolution($this->tokenStorage->getToken(), ApiScope::Mcp);
    }

    /**
     * The project bound to the MCP credential carried by a specific security token.
     *
     * A Voter is handed the security token it must judge, which is not
     * necessarily the one in storage, so the binding is resolved from the
     * argument rather than from TokenStorage. It answers with a project or with
     * nothing, because a Voter must vote rather than throw.
     */
    public function resolveMcpProjectFor(?TokenInterface $securityToken): ?Project
    {
        return $this->resolve($securityToken, ApiScope::Mcp);
    }

    private function resolve(?TokenInterface $securityToken, ApiScope $scope): ?Project
    {
        return $this->resolution($securityToken, $scope)->project;
    }

    private function resolution(?TokenInterface $securityToken, ApiScope $scope): ProjectResolution
    {
        $credential = AuthenticatedCredential::of($securityToken);
        if (null === $credential) {
            return ProjectResolution::refused(ProjectRefusal::NoCredential);
        }
        if (!$credential->hasRole($scope->role())) {
            return ProjectResolution::refused(ProjectRefusal::WrongScope);
        }

        $requested = $this->requestedProject();
        if (false === $requested) {
            return $this->refuse(ProjectRefusal::HeaderMalformed, []);
        }

        return $credential->allProjects
            ? $this->acrossOwnedProjects($securityToken, $requested)
            : $this->withinOneProject($credential, $requested);
    }

    /**
     * A credential covering every project of its owner. The header chooses one,
     * and with no header the owner's single project is the only unambiguous
     * answer.
     */
    private function acrossOwnedProjects(?TokenInterface $securityToken, ?Uuid $requested): ProjectResolution
    {
        $user = $securityToken?->getUser();
        if (!$user instanceof User) {
            return ProjectResolution::refused(ProjectRefusal::NoCredential);
        }

        if (null === $requested) {
            $owned = $this->projects->findByOwner($user);

            return 1 === \count($owned)
                ? ProjectResolution::of($owned[0])
                : $this->refuse(ProjectRefusal::SeveralProjectsAndNoHeader, $owned);
        }

        $project = $this->projects->findOneByIdOrSlugForOwner($requested->toRfc4122(), $user);

        return null !== $project
            ? ProjectResolution::of($project)
            : $this->refuse(ProjectRefusal::HeaderNotCovered, $this->projects->findByOwner($user));
    }

    /** A credential bound to one project. A header may confirm it, never change it. */
    private function withinOneProject(AuthenticatedCredential $credential, ?Uuid $requested): ProjectResolution
    {
        $bound = null !== $credential->projectId ? $this->projects->find($credential->projectId) : null;
        if (null === $bound) {
            return ProjectResolution::refused(ProjectRefusal::Unbound);
        }
        if (null !== $requested && $bound->id?->toRfc4122() !== $requested->toRfc4122()) {
            return $this->refuse(ProjectRefusal::HeaderNotCovered, [$bound]);
        }

        return ProjectResolution::of($bound);
    }

    /**
     * The project the request asks for: null for no header, false for a header
     * that is not a project id.
     */
    private function requestedProject(): Uuid|false|null
    {
        // No request at all on the console, which is not a header problem.
        $header = $this->requests->getCurrentRequest()?->headers->get(self::PROJECT_HEADER);
        if (null === $header || '' === trim($header)) {
            return null;
        }

        $value = trim($header);

        return Uuid::isValid($value) ? Uuid::fromString($value) : false;
    }

    /**
     * Records the refusals the header introduced. The others are pre-existing
     * silent answers that McpBoundProjectVoter already audits.
     *
     * @param list<Project> $covered
     */
    private function refuse(ProjectRefusal $refusal, array $covered): ProjectResolution
    {
        $this->logger->info('project.mcp_project_refused', [
            'reason' => $refusal->value,
            'requestedProjectId' => $this->requests->getCurrentRequest()?->headers->get(self::PROJECT_HEADER),
            'coveredProjectCount' => \count($covered),
        ]);

        return ProjectResolution::refused($refusal, $covered);
    }
}
