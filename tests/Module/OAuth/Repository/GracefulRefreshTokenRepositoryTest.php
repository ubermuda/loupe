<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\OAuth\Repository\GracefulRefreshTokenRepository;
use App\Tests\Support\CountingCachePool;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class GracefulRefreshTokenRepositoryTest extends TestCase
{
    private const string TOKEN = 'refresh-token-identifier';

    public function test_a_live_token_is_not_revoked(): void
    {
        $repository = $this->repository(60);

        self::assertFalse($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    public function test_a_rotated_token_is_accepted_once_more(): void
    {
        $repository = $this->repository(60);
        $repository->revokeRefreshToken(self::TOKEN);

        self::assertFalse($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    public function test_the_window_closes_after_one_use(): void
    {
        $repository = $this->repository(60);
        $repository->revokeRefreshToken(self::TOKEN);

        self::assertFalse($repository->isRefreshTokenRevoked(self::TOKEN));
        self::assertTrue($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    /**
     * The grant revokes the old token again on the accepted retry. Re-arming
     * there would keep it redeemable for as long as it was presented.
     */
    public function test_the_retry_does_not_re_arm_the_window(): void
    {
        $repository = $this->repository(60);
        $repository->revokeRefreshToken(self::TOKEN);

        self::assertFalse($repository->isRefreshTokenRevoked(self::TOKEN));
        $repository->revokeRefreshToken(self::TOKEN);

        self::assertTrue($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    public function test_zero_seconds_switches_the_window_off(): void
    {
        $repository = $this->repository(0);
        $repository->revokeRefreshToken(self::TOKEN);

        self::assertTrue($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    /**
     * A disabled window writes nothing, rather than writing a marker that
     * happens to expire at once. A pool that read a zero lifetime as "no
     * expiry" would otherwise turn the switch into always-on, and an expired
     * item is indistinguishable from an absent one after the fact.
     */
    public function test_zero_seconds_writes_nothing_to_the_pool(): void
    {
        $pool = new CountingCachePool();
        $repository = new GracefulRefreshTokenRepository($this->inner(), $pool, new NullLogger(), 0);

        $repository->revokeRefreshToken(self::TOKEN);

        self::assertSame(0, $pool->saves);
    }

    public function test_a_window_that_is_on_writes_one_marker(): void
    {
        $pool = new CountingCachePool();
        $repository = new GracefulRefreshTokenRepository($this->inner(), $pool, new NullLogger(), 60);

        $repository->revokeRefreshToken(self::TOKEN);

        self::assertSame(1, $pool->saves);
    }

    /**
     * A token revoked any other way, such as by disconnecting the app, opened
     * no window and must stay refused.
     */
    public function test_a_token_revoked_without_rotating_stays_refused(): void
    {
        $inner = $this->inner();
        $repository = new GracefulRefreshTokenRepository($inner, new ArrayAdapter(), new NullLogger(), 60);

        $inner->revokeRefreshToken(self::TOKEN);

        self::assertTrue($repository->isRefreshTokenRevoked(self::TOKEN));
    }

    public function test_one_rotated_token_does_not_open_a_window_for_another(): void
    {
        $inner = $this->inner();
        $repository = new GracefulRefreshTokenRepository($inner, new ArrayAdapter(), new NullLogger(), 60);

        $repository->revokeRefreshToken(self::TOKEN);
        $inner->revokeRefreshToken('another-token-identifier');

        self::assertTrue($repository->isRefreshTokenRevoked('another-token-identifier'));
    }

    private function repository(int $seconds): GracefulRefreshTokenRepository
    {
        return new GracefulRefreshTokenRepository($this->inner(), new ArrayAdapter(), new NullLogger(), $seconds);
    }

    /** An in-memory stand-in for the bundle's own repository. */
    private function inner(): RefreshTokenRepositoryInterface
    {
        return new class implements RefreshTokenRepositoryInterface {
            /** @var array<string, true> */
            private array $revoked = [];

            #[\Override]
            public function getNewRefreshToken(): ?RefreshTokenEntityInterface
            {
                return null;
            }

            #[\Override]
            public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
            {
            }

            #[\Override]
            public function revokeRefreshToken(string $tokenId): void
            {
                $this->revoked[$tokenId] = true;
            }

            #[\Override]
            public function isRefreshTokenRevoked(string $tokenId): bool
            {
                return isset($this->revoked[$tokenId]);
            }
        };
    }
}
