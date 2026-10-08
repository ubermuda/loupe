<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

use App\Module\Account\Entity\User;
use App\Module\GitHub\Entity\GitHubUserConnection;
use App\Module\GitHub\Repository\GitHubUserConnectionRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Gives a caller a working access token for a user, and refreshes it when it
 * is near its end. A refresh token works once, so a refresh holds a row lock
 * from the read to the write. A second request waits, then sees the new pair.
 */
final readonly class GitHubUserTokenRefresher
{
    /** A token that ends within this many seconds counts as ended, so a call does not start with a dying token. */
    private const int MARGIN_SECONDS = 120;

    private const string BAD_REFRESH_TOKEN = 'token_bad_refresh_token';

    public function __construct(
        private GitHubUserConnectionRepository $gitHubUserConnections,
        private GitHubUserApi $api,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function accessTokenFor(User $user): GitHubUserTokenResult
    {
        $connection = $this->gitHubUserConnections->findOneByUser($user);
        if (null === $connection) {
            return GitHubUserTokenResult::without(GitHubUserTokenStatus::NotConnected);
        }

        $early = $this->usable($connection);
        if (null !== $early) {
            return $early;
        }

        return $this->em->wrapInTransaction(function () use ($connection): GitHubUserTokenResult {
            // The lock reloads the row, so a refresh that finished meanwhile shows.
            $this->em->refresh($connection, LockMode::PESSIMISTIC_WRITE);

            return $this->usable($connection) ?? $this->refresh($connection);
        });
    }

    private function usable(GitHubUserConnection $connection): ?GitHubUserTokenResult
    {
        if (null !== $connection->expiredAt) {
            return GitHubUserTokenResult::without(GitHubUserTokenStatus::Expired);
        }

        $limit = $this->clock->now()->modify('+'.self::MARGIN_SECONDS.' seconds');
        if ($connection->accessTokenExpiresAt > $limit && '' !== $connection->accessToken) {
            return GitHubUserTokenResult::fresh($connection->accessToken);
        }

        return null;
    }

    private function refresh(GitHubUserConnection $connection): GitHubUserTokenResult
    {
        $now = $this->clock->now();
        if ($connection->refreshTokenExpiresAt <= $now) {
            return $this->expire($connection, 'refresh_token_ended');
        }

        try {
            $tokens = $this->api->refresh($connection->refreshToken);
        } catch (GitHubUserApiFailed $e) {
            if (self::BAD_REFRESH_TOKEN === $e->reason) {
                return $this->expire($connection, $e->reason);
            }

            $this->logger->warning('github.user_token_refresh_failed', [
                'userId' => (string) $connection->user->id,
                'reason' => $e->reason,
                'status' => $e->status,
            ]);

            return GitHubUserTokenResult::without(GitHubUserTokenStatus::Unavailable);
        }

        if (!$tokens->canRefresh()) {
            // GitHub spent the old refresh token and sent no new one.
            return $this->expire($connection, 'refresh_pair_missing');
        }

        $connection->accessToken = $tokens->accessToken;
        $connection->refreshToken = $tokens->refreshToken ?? throw new \LogicException('canRefresh() proved the refresh token');
        $connection->accessTokenExpiresAt = $tokens->accessTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry');
        $connection->refreshTokenExpiresAt = $tokens->refreshTokenExpiresAt ?? throw new \LogicException('canRefresh() proved the expiry');
        $this->em->flush();

        return GitHubUserTokenResult::fresh($tokens->accessToken);
    }

    private function expire(GitHubUserConnection $connection, string $reason): GitHubUserTokenResult
    {
        $connection->expiredAt = $this->clock->now();
        $this->em->flush();
        $this->logger->info('github.user_connection_expired', [
            'userId' => (string) $connection->user->id,
            'reason' => $reason,
        ]);

        return GitHubUserTokenResult::without(GitHubUserTokenStatus::Expired);
    }
}
