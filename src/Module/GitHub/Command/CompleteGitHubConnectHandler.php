<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Exception\DomainErrors;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\Service\SiteOrigins;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Stores the GitHub account for the signed-in person. The row keys on the
 * Loupe user and not on the GitHub account, so a second connect replaces the
 * first.
 */
final readonly class CompleteGitHubConnectHandler
{
    public function __construct(
        private GitHubUserApi $gitHubUserApi,
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private ProjectRepository $projects,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteGitHubConnectCommand $command): CompletedGitHubConnect
    {
        if (null === $command->state || !hash_equals($command->pending->state, $command->state)) {
            throw new DomainErrors(['connection' => 'github.connect.flash.state_mismatch']);
        }

        if (null === $command->code || '' === $command->code) {
            throw new DomainErrors(['connection' => 'github.connect.flash.not_authorized']);
        }

        try {
            $tokens = $this->gitHubUserApi->exchangeCode($command->code, $command->pending->codeVerifier, $command->redirectUri);
            if (!$tokens->canRefresh()) {
                throw new DomainErrors(['connection' => 'github.connect.flash.tokens_do_not_expire']);
            }

            $profile = $this->gitHubUserApi->user($tokens->accessToken);
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_api_failed', [
                'userId' => (string) $command->user->id,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);

            throw new DomainErrors(['connection' => 'github.connect.flash.github_unavailable']);
        }

        $now = $this->clock->now();
        $connection = $this->gitHubUserConnections->findOneByUser($command->user);
        if (null === $connection) {
            $this->em->persist(new GitHubUserConnection(
                $command->user,
                $profile->id,
                $profile->login,
                $tokens->accessToken,
                $tokens->refreshToken ?? throw new \LogicException('canRefresh() proved the refresh token'),
                $tokens->accessTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry'),
                $tokens->refreshTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry'),
                $now,
            ));
        } else {
            $connection->githubUserId = $profile->id;
            $connection->login = $profile->login;
            $connection->accessToken = $tokens->accessToken;
            $connection->refreshToken = $tokens->refreshToken ?? throw new \LogicException('canRefresh() proved the refresh token');
            $connection->accessTokenExpiresAt = $tokens->accessTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry');
            $connection->refreshTokenExpiresAt = $tokens->refreshTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry');
            $connection->connectedAt = $now;
            $connection->expiredAt = null;
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // A concurrent callback of the same person stored the row first.
            throw new DomainErrors(['connection' => 'github.connect.flash.github_unavailable']);
        }

        $this->auditor->record(
            'github.user_connected',
            AuditOutcome::Success,
            ['userId' => (string) $command->user->id, 'githubUserId' => $profile->id],
            new AuditSubject('user', (string) $command->user->id),
        );

        return new CompletedGitHubConnect($profile->login, $this->targetOrigin($command));
    }

    /** The origin comes from the pending connection and must still be on the project's allowed sites. */
    private function targetOrigin(CompleteGitHubConnectCommand $command): ?string
    {
        $origin = $command->pending->origin;
        $projectId = $command->pending->projectId;
        if (null === $origin || null === $projectId || !Uuid::isValid($projectId)) {
            return null;
        }

        $project = $this->projects->find(Uuid::fromString($projectId));

        return null !== $project && SiteOrigins::allows($project->allowedOrigins, $origin) ? $origin : null;
    }
}
