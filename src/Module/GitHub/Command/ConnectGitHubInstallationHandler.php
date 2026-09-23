<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\Forge\Service\ForgeClaimOutcome;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\GitHubDelivery;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Module\GitHub\Security\GitHubConnectionVoter;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * GitHub warns that the installation id on the setup URL can be forged, so it
 * binds only when the installing user's own token lists that installation.
 */
final readonly class ConnectGitHubInstallationHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private AuthorizationCheckerInterface $authorization,
        private GitHubUserApi $gitHubUserApi,
        private GitHubInstallationRepository $gitHubInstallations,
        private ForgeRepositories $forgeRepositories,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ConnectGitHubInstallationCommand $command): ConnectedGitHubInstallation
    {
        $project = Uuid::isValid($command->projectId) ? $this->projects->find(Uuid::fromString($command->projectId)) : null;
        if (null === $project || !$this->authorization->isGranted(GitHubConnectionVoter::MANAGE, $project)) {
            throw new DomainErrors(['project' => 'github.app.flash.project_unavailable']);
        }

        try {
            $token = $this->gitHubUserApi->exchangeCode($command->code, $command->codeVerifier, $command->redirectUri);
            $listed = array_find($this->gitHubUserApi->installations($token), static fn ($installation): bool => $installation->id === $command->installationId);
            if (null === $listed) {
                $this->refused($project, $command->installationId, 'not_listed_for_user');

                throw new DomainErrors(['installation' => 'github.app.flash.installation_not_listed']);
            }

            $repositories = $this->gitHubUserApi->installationRepositories($token, $command->installationId);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'projectId' => (string) $project->id,
                'installationId' => $command->installationId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);

            throw new DomainErrors(['installation' => 'github.app.flash.github_unavailable']);
        }

        $installation = $this->gitHubInstallations->findOneByInstallationId($command->installationId);
        if (null !== $installation && !$this->belongsTo($installation, $project)) {
            $this->refused($project, $command->installationId, 'other_project');

            throw new DomainErrors(['installation' => 'github.app.flash.installation_elsewhere']);
        }

        if (null === $installation) {
            $installation = new GitHubInstallation($project, $command->installationId, $listed->accountLogin, $listed->repositorySelection);
            $this->em->persist($installation);
        } else {
            $installation->accountLogin = $listed->accountLogin;
            $installation->repositorySelection = $listed->repositorySelection;
            $installation->removedAt = null;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent callback bound the same installation first.
            throw new DomainErrors(['installation' => 'github.app.flash.installation_elsewhere']);
        }

        $refused = 0;
        foreach ($repositories as $repository) {
            $claim = $this->forgeRepositories->claim($project, GitHubDelivery::FORGE, $repository->externalId(), $repository->fullName);
            if (ForgeClaimOutcome::Refused === $claim->outcome) {
                ++$refused;
                $this->logger->info('github.repository_refused', [
                    'installationId' => $command->installationId,
                    'repositoryId' => $repository->id,
                ]);
            }
        }

        $this->auditor->record(
            'github.installation_connected',
            AuditOutcome::Success,
            [
                'projectId' => (string) $project->id,
                'installationId' => $command->installationId,
                'repositories' => \count($repositories),
                'refusedRepositories' => $refused,
            ],
            new AuditSubject('project', (string) $project->id),
        );

        return new ConnectedGitHubInstallation($installation, $refused);
    }

    private function belongsTo(GitHubInstallation $installation, Project $project): bool
    {
        return null !== $project->id && true === $installation->project->id?->equals($project->id);
    }

    private function refused(Project $project, int $installationId, string $reason): void
    {
        $this->logger->info('github.installation_refused', [
            'projectId' => (string) $project->id,
            'installationId' => $installationId,
            'reason' => $reason,
        ]);
    }
}
