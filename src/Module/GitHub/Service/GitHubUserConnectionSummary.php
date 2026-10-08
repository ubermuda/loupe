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

    public function isExpired(): bool
    {
        return null !== $this->expiredAt;
    }
}
