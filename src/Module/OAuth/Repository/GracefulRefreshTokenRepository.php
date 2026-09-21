<?php

declare(strict_types=1);

namespace App\Module\OAuth\Repository;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Accepts a refresh token once more for a short time after it rotated.
 *
 * The server revokes the old refresh token and issues the new pair before the
 * client has persisted anything. A response lost on the way back therefore
 * leaves the client holding a token the server has already refused, and no
 * endpoint hands the new one back, so the login is dead until the person signs
 * in again. The server is the only party that knows the rotation happened, so
 * the recovery has to live here.
 *
 * The window accepts the old token once and then closes, rather than staying
 * open for its whole length. Either the client's retry redeems it or nobody
 * does, so a captured token cannot be redeemed again and again.
 */
#[AsDecorator('league.oauth2_server.repository.refresh_token')]
#[WithMonologChannel('security')]
final readonly class GracefulRefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private const string KEY_PREFIX = 'oauth_refresh_grace_';

    public function __construct(
        #[AutowireDecorated]
        private RefreshTokenRepositoryInterface $inner,

        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $grace,
        private LoggerInterface $logger,

        #[Autowire(param: 'app.oauth.refresh_grace_seconds')]
        private int $seconds = 60,
    ) {
    }

    #[\Override]
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return $this->inner->getNewRefreshToken();
    }

    #[\Override]
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->inner->persistNewRefreshToken($refreshTokenEntity);
    }

    #[\Override]
    public function revokeRefreshToken(string $tokenId): void
    {
        // Only the first revocation opens a window. A retry inside one revokes
        // the same token again, and re-arming there would keep the old token
        // redeemable for as long as it was presented.
        $opensWindow = $this->seconds > 0 && !$this->inner->isRefreshTokenRevoked($tokenId);

        $this->inner->revokeRefreshToken($tokenId);

        if (!$opensWindow) {
            return;
        }

        $item = $this->grace->getItem(self::keyFor($tokenId));
        $item->set(true);
        $item->expiresAfter($this->seconds);
        $this->grace->save($item);
    }

    /**
     * Consuming the marker inside a question is a side effect, and it is where
     * league gives the only hook: it asks this once per refresh, before it
     * accepts the token.
     */
    #[\Override]
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        if (!$this->inner->isRefreshTokenRevoked($tokenId)) {
            return false;
        }
        if ($this->seconds <= 0) {
            return true;
        }

        $key = self::keyFor($tokenId);
        if (!$this->grace->hasItem($key)) {
            return true;
        }

        $this->grace->deleteItem($key);
        $this->logger->info('oauth.refresh_token_grace_used', ['graceSeconds' => $this->seconds]);

        return false;
    }

    /** Hashed, so no token identifier reaches a cache key or a log line. */
    private static function keyFor(string $tokenId): string
    {
        return self::KEY_PREFIX.hash('sha256', $tokenId);
    }
}
