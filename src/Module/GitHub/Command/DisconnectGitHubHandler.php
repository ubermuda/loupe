<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use App\Module\GitHub\Service\GitHubUserApi;
use App\Module\GitHub\Service\GitHubUserApiFailed;
use App\Module\GitHub\Service\GitHubUserTokenRefresher;
use App\Module\GitHub\Service\GitHubUserTokenStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Asks GitHub to void the grant, then deletes the row. A failed call must not
 * keep a person connected, so the delete runs whatever the call does.
 */
final readonly class DisconnectGitHubHandler
{
    public function __construct(
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private GitHubUserTokenRefresher $tokens,
        private GitHubUserApi $gitHubUserApi,
        private Auditor $auditor,
        private LoggerInterface $logger,
    ) {
    }

    /** @return bool whether GitHub confirmed the revoke */
    public function __invoke(DisconnectGitHubCommand $command): bool
    {
        $userId = (string) ($command->user->id ?? throw new \LogicException('a persisted user always has an id'));
        if (null === $this->gitHubUserConnections->findSummaryByUser($command->user)) {
            return true;
        }

        $revoked = false;
        try {
            $token = $this->tokens->accessTokenFor($command->user);
            if (GitHubUserTokenStatus::Fresh === $token->status && null !== $token->accessToken) {
                $this->gitHubUserApi->revokeGrant($token->accessToken);
                $revoked = true;
            }
        } catch (GitHubUserApiFailed $e) {
            $this->logger->warning('github.user_revoke_failed', [
                'userId' => $userId,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);
        } finally {
            $this->gitHubUserConnections->deleteForUser(Uuid::fromString($userId));
        }

        $this->auditor->record(
            'github.user_disconnected',
            AuditOutcome::Success,
            ['userId' => $userId, 'revoked' => $revoked],
            new AuditSubject('user', $userId),
        );

        return $revoked;
    }
}
