<?php

declare(strict_types=1);

namespace App\Module\GitHub\Service;

/** What a page or an export may show of a connection. It holds no token. */
final readonly class GitHubUserConnectionSummary
{
    public function __construct(
        public string $login,
        public \DateTimeImmutable $connectedAt,
        public ?\DateTimeImmutable $expiredAt,
        public \DateTimeImmutable $accessTokenExpiresAt,
        public \DateTimeImmutable $refreshTokenExpiresAt,
    ) {
    }

    /** A connection with an ended refresh token cannot refresh, so it counts as expired before Loupe finds out. */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return null !== $this->expiredAt || $this->refreshTokenExpiresAt <= $now;
    }
}
